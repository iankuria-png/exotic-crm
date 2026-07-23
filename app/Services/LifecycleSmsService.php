<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientNote;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Template;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\Messaging\MessageRecipient;
use App\Services\Messaging\MessagingDispatcher;
use App\Support\CrmAuditAction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates every client-facing lifecycle SMS (onboarding, failed-payment
 * recovery, reactivation, renewal payment links) through ONE pipeline:
 *
 *   trigger → market gates → state re-check → dedup + rate cap
 *           → tokenized payment link → template render → dispatch → record
 *
 * Both the automated sweeps/jobs and the manual conversion-queue actions call
 * this service, so dedup, state gating, and telemetry are shared — a human can
 * never double-send on top of the automation, and vice versa.
 */
class LifecycleSmsService
{
    public const FLOW_ONBOARDING = 'onboarding';
    public const FLOW_RECOVERY = 'recovery';
    public const FLOW_REACTIVATION = 'reactivation';
    public const FLOW_RENEWAL = 'renewal';

    public const TIMELINE_EVENT_TYPE = 'lifecycle_sms';

    /** Template categories, in preference order, per flow. */
    public const FLOW_TEMPLATE_CATEGORIES = [
        self::FLOW_ONBOARDING => ['welcome'],
        self::FLOW_RECOVERY => ['payment'],
        self::FLOW_REACTIVATION => ['win_back'],
        self::FLOW_RENEWAL => ['renewal'],
    ];

    public function __construct(
        private readonly LifecycleSmsSettingsService $settings,
        private readonly NotificationService $notificationService,
        private readonly PaymentLinkService $paymentLinkService,
        private readonly DealPaymentService $dealPaymentService,
        private readonly TemplateService $templateService,
        private readonly AuditService $auditService,
        private readonly PaymentAttemptService $paymentAttemptService,
        private readonly MessagingDispatcher $messagingDispatcher,
        private readonly ClientProfileMetricsService $profileMetricsService
    ) {
    }

    // ---------------------------------------------------------------
    // Capabilities (drives the Lifecycle tab badges + hard gates)
    // ---------------------------------------------------------------

    public function capabilitiesForPlatform(Platform $platform): array
    {
        $smsConfig = $this->notificationService->currentSmsConfig(masked: true);
        $marketEntry = $smsConfig['markets'][(string) $platform->id] ?? [];
        $activeProvider = (string) (($marketEntry['active_provider'] ?? null) ?: ($smsConfig['active_provider'] ?? ''));

        $smsReady = (bool) ($smsConfig['enabled'] ?? false)
            && $activeProvider !== ''
            && $this->notificationService->providerConfigured($activeProvider, (int) $platform->id);

        $templates = [];
        foreach (LifecycleSmsSettingsService::FLOWS as $flow) {
            $templates[$flow] = $this->resolveTemplate($flow, (int) $platform->id) !== null;
        }

        return [
            'sms_ready' => $smsReady,
            'sms_provider' => $activeProvider ?: null,
            'psp_ready' => $this->paymentLinkService->hasTokenizedProvider($platform),
            'templates' => $templates,
        ];
    }

    // ---------------------------------------------------------------
    // Send pipeline
    // ---------------------------------------------------------------

    /**
     * Send one lifecycle SMS to one client. Returns a structured outcome:
     * ['status' => 'sent'|'skipped'|'failed', 'skip_reason' => ?, ...].
     *
     * Options: actor_id, payment (Payment, required for recovery), reference
     * (dedup key override), source ('automated'|'manual'), dry_run.
     */
    public function send(string $flow, Client $client, array $options = []): array
    {
        $evaluation = $this->evaluate($flow, $client, $options);
        if (($evaluation['status'] ?? '') !== 'ok') {
            return $evaluation;
        }

        if (!empty($options['dry_run'])) {
            return array_merge($evaluation, ['status' => 'would_send']);
        }

        $platform = $client->platform;
        $marketConfig = $evaluation['market_config'];
        $flowConfig = $evaluation['flow_config'];
        $reference = $evaluation['reference'];
        $actorId = $this->resolveActorId($options['actor_id'] ?? null);

        // Resolve (or mint) the payment + tokenized link.
        $payment = $options['payment'] ?? null;
        $deal = null;
        if ($flow === self::FLOW_RECOVERY) {
            $payment->loadMissing('deal');
            $deal = $payment->deal;
        } else {
            try {
                [$deal, $payment] = $this->resolveProFormaDealAndPayment($client, $flow, $flowConfig, $actorId);
            } catch (\Throwable $exception) {
                return $this->recordOutcome($flow, $client, 'failed', [
                    'reference' => $reference,
                    'error' => $exception->getMessage(),
                ], $actorId);
            }
        }

        $link = $this->paymentLinkService->prepareTokenizedUrl($payment, [
            'notification_purpose' => 'lifecycle_' . $flow,
        ]);
        if (!($link['success'] ?? false)) {
            return $this->skip($flow, $client, (string) ($link['skip_reason'] ?? 'link_unavailable'));
        }

        $template = $evaluation['template'];
        $variables = $this->buildVariables($client, $deal, $payment, (string) $link['payment_url']);
        $rendered = $this->templateService->renderTemplate($template, $variables);

        if (!empty($rendered['missing'])) {
            return $this->recordOutcome($flow, $client, 'failed', [
                'reference' => $reference,
                'template_id' => (int) $template->id,
                'error' => 'Missing template variables: ' . implode(', ', $rendered['missing']),
            ], $actorId);
        }

        $delivery = $this->dispatchMessage($client, (string) $rendered['body'], (string) $marketConfig['channel'], [
            'purpose' => 'lifecycle_' . $flow,
            'phone_prefix' => (string) ($platform?->phone_prefix ?: '254'),
            'template_id' => (int) $template->id,
            'actor_id' => $actorId,
        ]);

        if (($delivery['status'] ?? '') === 'disabled') {
            return $this->skip($flow, $client, 'sms_dispatch_disabled');
        }

        $success = (bool) ($delivery['success'] ?? false);

        $this->paymentAttemptService->record($payment, 'lifecycle_' . $flow . '_sms', $success ? 'success' : 'failed', [
            'provider' => $delivery['provider'] ?? null,
            'error_code' => $success ? null : 'lifecycle_sms_failed',
            'error_message' => $success ? null : (string) ($delivery['provider_response'] ?? 'Message could not be sent.'),
            'request_meta' => ['flow' => $flow, 'reference' => $reference, 'channel' => $marketConfig['channel']],
            'response_meta' => [
                'message_status' => $delivery['status'] ?? null,
                'payment_url' => $link['payment_url'],
            ],
            'created_by' => $actorId,
        ]);

        return $this->recordOutcome($flow, $client, $success ? 'sent' : 'failed', [
            'reference' => $reference,
            'template_id' => (int) $template->id,
            'deal_id' => $deal?->id,
            'payment_id' => (int) $payment->id,
            'payment_url' => (string) $link['payment_url'],
            'body' => (string) $rendered['body'],
            'channel' => (string) $marketConfig['channel'],
            'delivered_channel' => $delivery['channel'] ?? $marketConfig['channel'],
            'provider_response' => $delivery['provider_response'] ?? null,
            'source' => (string) ($options['source'] ?? 'automated'),
        ], $actorId);
    }

    /**
     * All gates WITHOUT side effects. Returns ['status' => 'ok', ...context]
     * when the send may proceed, else a skip result with the reason.
     */
    public function evaluate(string $flow, Client $client, array $options = []): array
    {
        $client->loadMissing('platform');
        $platform = $client->platform;
        if (!$platform) {
            return $this->skipResult($flow, $client, 'missing_platform');
        }

        if (!$this->settings->globalEnabled()) {
            return $this->skipResult($flow, $client, 'disabled_global');
        }

        $marketConfig = $this->settings->marketConfig((int) $platform->id);
        if (!$marketConfig['sms_enabled']) {
            return $this->skipResult($flow, $client, 'market_sms_disabled');
        }

        $flowConfig = $marketConfig[$flow] ?? [];
        $flowEnabled = $flow === self::FLOW_RENEWAL
            ? (bool) ($flowConfig['payment_link_enabled'] ?? false)
            : (bool) ($flowConfig['enabled'] ?? false);
        if (!$flowEnabled) {
            return $this->skipResult($flow, $client, 'flow_disabled');
        }

        if ($client->closed_at) {
            return $this->skipResult($flow, $client, 'client_closed');
        }

        if (!$client->phone_normalized) {
            return $this->skipResult($flow, $client, 'missing_phone');
        }

        if ($client->reminders_paused_until && $client->reminders_paused_until->isFuture()) {
            return $this->skipResult($flow, $client, 'reminders_paused');
        }

        // Payment-provider capability: tokenized only, checked BEFORE any
        // deal/payment is minted so a no-PSP market causes zero churn.
        if (!$this->paymentLinkService->hasTokenizedProvider($platform)) {
            return $this->skipResult($flow, $client, 'market_no_psp');
        }

        // Quiet hours (market timezone): automated sends hold overnight; manual
        // sends from the conversion queue are an agent's judgment call and pass.
        if (($options['source'] ?? 'automated') === 'automated'
            && empty($options['dry_run'])
            && $this->inQuietHours($platform)) {
            return $this->skipResult($flow, $client, 'quiet_hours');
        }

        // State re-check against the LOCAL source of truth (escort_expire +
        // payment statuses) — never live WP. A client who converted after the
        // trigger must not receive this reminder.
        $stateSkip = $this->stateGate($flow, $client, $options);
        if ($stateSkip !== null) {
            return $this->skipResult($flow, $client, $stateSkip);
        }

        $reference = (string) ($options['reference'] ?? $this->defaultReference($flow, $client, $options));

        if ($this->alreadySent($client, $flow, $reference)) {
            return $this->skipResult($flow, $client, 'already_sent');
        }

        if ($this->rateCapReached($client, $marketConfig)) {
            return $this->skipResult($flow, $client, 'rate_capped');
        }

        $template = $this->resolveTemplate($flow, (int) $platform->id, $flowConfig['template_id'] ?? null);
        if (!$template) {
            return $this->skipResult($flow, $client, 'no_template');
        }

        if (in_array($flow, [self::FLOW_ONBOARDING, self::FLOW_REACTIVATION], true)) {
            if (empty($flowConfig['product_id']) || empty($flowConfig['product_price_id'])) {
                return $this->skipResult($flow, $client, 'no_offer_configured');
            }
        }

        return [
            'status' => 'ok',
            'flow' => $flow,
            'client_id' => (int) $client->id,
            'market_config' => $marketConfig,
            'flow_config' => $flowConfig,
            'reference' => $reference,
            'template' => $template,
        ];
    }

    // ---------------------------------------------------------------
    // State gates (client-level, local source of truth)
    // ---------------------------------------------------------------

    private function stateGate(string $flow, Client $client, array $options): ?string
    {
        switch ($flow) {
            case self::FLOW_RECOVERY:
                $payment = $options['payment'] ?? null;
                if (!$payment instanceof Payment) {
                    return 'missing_payment';
                }
                if ((string) $payment->status !== 'failed') {
                    return 'payment_not_failed';
                }
                if ($payment->isSandboxTest() || $payment->isClassifiedTest()) {
                    return 'test_payment';
                }
                // Manual payments: the client likely already paid by proof and
                // is awaiting operator review — "your payment failed" is wrong.
                if ($payment->manual_payment_bundle_id
                    || (string) $payment->provider_key === 'manual_confirmation'
                    || (string) $payment->reconciliation_state === 'manual_review') {
                    return 'manual_payment';
                }
                // Sibling check: the failed Payment row never flips to
                // completed — gate on whether the CLIENT is now active.
                if ($this->clientHasActiveSubscription($client)) {
                    return 'client_already_active';
                }
                return null;

            case self::FLOW_ONBOARDING:
                if (!in_array((string) $client->signup_source, ['fast_signup', 'full_registration'], true)) {
                    return 'signup_source_excluded';
                }
                if ($this->clientHasActiveSubscription($client)) {
                    return 'client_already_active';
                }
                return null;

            case self::FLOW_REACTIVATION:
            case self::FLOW_RENEWAL:
                if ($this->clientHasActiveSubscription($client)) {
                    return 'client_already_active';
                }
                return null;
        }

        return 'unknown_flow';
    }

    public function clientHasActiveSubscription(Client $client): bool
    {
        if (!empty($client->escort_expire)
            && is_numeric($client->escort_expire)
            && (int) $client->escort_expire > now()->timestamp) {
            return true;
        }

        $activeDeal = $client->deals()
            ->whereIn('status', ['active', 'paid'])
            ->where(function (Builder $builder) {
                $builder->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();
        if ($activeDeal) {
            return true;
        }

        return $client->payments()
            ->whereIn('status', Payment::ACTIVE_SUBSCRIPTION_STATUSES)
            ->where('end_date', '>', now())
            ->exists();
    }

    // ---------------------------------------------------------------
    // Dedup + rate cap (timeline-backed, shared automated/manual)
    // ---------------------------------------------------------------

    private function defaultReference(string $flow, Client $client, array $options): string
    {
        return match ($flow) {
            self::FLOW_ONBOARDING => 'onboarding',
            self::FLOW_RECOVERY => 'payment:' . (int) (($options['payment'] ?? null)?->id ?? 0),
            self::FLOW_REACTIVATION => 'window:' . (int) ($options['window_days'] ?? 0)
                . ':' . (int) ($client->escort_expire ?: 0),
            self::FLOW_RENEWAL => 'renewal:' . now()->toDateString(),
            default => $flow,
        };
    }

    private function dedupWindowDays(string $flow): int
    {
        return match ($flow) {
            self::FLOW_ONBOARDING => 365,
            self::FLOW_RECOVERY => 30,
            self::FLOW_REACTIVATION => 60,
            default => 30,
        };
    }

    public function alreadySent(Client $client, string $flow, string $reference): bool
    {
        return $this->recentLifecycleEvents($client, $this->dedupWindowDays($flow))
            ->contains(function (TimelineEvent $event) use ($flow, $reference) {
                $content = is_array($event->content) ? $event->content : [];

                return ($content['flow'] ?? null) === $flow
                    && ($content['reference'] ?? null) === $reference
                    && in_array($content['status'] ?? null, ['sent'], true);
            });
    }

    /** Most recent lifecycle send for one client+flow (for the queue UI). */
    public function lastSendFor(Client $client, ?string $flow = null): ?array
    {
        $event = $this->recentLifecycleEvents($client, 90)
            ->first(function (TimelineEvent $event) use ($flow) {
                $content = is_array($event->content) ? $event->content : [];

                return $flow === null || ($content['flow'] ?? null) === $flow;
            });

        if (!$event) {
            return null;
        }

        $content = is_array($event->content) ? $event->content : [];

        return [
            'flow' => $content['flow'] ?? null,
            'status' => $content['status'] ?? null,
            'reference' => $content['reference'] ?? null,
            'source' => $content['source'] ?? 'automated',
            'sent_at' => optional($event->created_at)?->toIso8601String(),
        ];
    }

    /**
     * Batched latest lifecycle-send state for many clients (conversion-queue
     * cockpit): [client_id => [flow => {status, source, sent_at}]].
     */
    public function lastSendsForClients(array $clientIds): array
    {
        $clientIds = array_values(array_filter(array_map('intval', $clientIds)));
        if ($clientIds === []) {
            return [];
        }

        $out = [];

        TimelineEvent::query()
            ->where('entity_type', 'client')
            ->whereIn('entity_id', $clientIds)
            ->where('event_type', self::TIMELINE_EVENT_TYPE)
            ->where('created_at', '>=', now()->subDays(90))
            ->orderByDesc('created_at')
            ->limit(1000)
            ->get()
            ->each(function (TimelineEvent $event) use (&$out) {
                $content = is_array($event->content) ? $event->content : [];
                $flow = (string) ($content['flow'] ?? '');
                $clientId = (int) $event->entity_id;
                if ($flow === '' || isset($out[$clientId][$flow])) {
                    return;
                }

                $out[$clientId][$flow] = [
                    'status' => $content['status'] ?? null,
                    'source' => $content['source'] ?? 'automated',
                    'reference' => $content['reference'] ?? null,
                    'sent_at' => optional($event->created_at)?->toIso8601String(),
                ];
            });

        return $out;
    }

    private function rateCapReached(Client $client, array $marketConfig): bool
    {
        $cap = (int) ($marketConfig['rate_cap_count'] ?? 0);
        if ($cap <= 0) {
            return false;
        }

        $windowDays = max(1, (int) ($marketConfig['rate_cap_days'] ?? 7));

        $sentCount = $this->recentLifecycleEvents($client, $windowDays)
            ->filter(function (TimelineEvent $event) {
                $content = is_array($event->content) ? $event->content : [];

                return ($content['status'] ?? null) === 'sent';
            })
            ->count();

        return $sentCount >= $cap;
    }

    private function recentLifecycleEvents(Client $client, int $windowDays)
    {
        return TimelineEvent::query()
            ->where('entity_type', 'client')
            ->where('entity_id', (int) $client->id)
            ->where('event_type', self::TIMELINE_EVENT_TYPE)
            ->where('created_at', '>=', now()->subDays($windowDays))
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();
    }

    // ---------------------------------------------------------------
    // Pro-forma deal + payment (onboarding / reactivation)
    // ---------------------------------------------------------------

    /**
     * Reuse an open pending lifecycle-origin deal+payment for (client, flow) —
     * cadence ticks must never mint parallel deals — else mint one with the
     * bonus-inflated duration on deal.duration_days ONLY.
     *
     * @return array{0: Deal, 1: Payment}
     */
    private function resolveProFormaDealAndPayment(Client $client, string $flow, array $flowConfig, int $actorId): array
    {
        $existing = Payment::query()
            ->with('deal')
            ->where('client_id', (int) $client->id)
            ->where('status', 'initiated')
            ->where('raw_payload->source', 'crm_lifecycle')
            ->where('raw_payload->lifecycle_flow', $flow)
            ->whereHas('deal', function (Builder $builder) {
                $builder->where('status', 'pending')
                    ->whereNull('pending_subsidiary_trial');
            })
            ->latest('id')
            ->first();

        if ($existing && $existing->deal) {
            return [$existing->deal, $existing];
        }

        $productId = (int) ($flowConfig['product_id'] ?? 0);
        $priceId = (int) ($flowConfig['product_price_id'] ?? 0);

        $product = Product::query()->findOrFail($productId);
        $price = ProductPrice::query()
            ->where('id', $priceId)
            ->where('product_id', $productId)
            ->where('is_active', true)
            ->firstOrFail();

        $planDays = max(1, (int) $price->duration_days);
        $bonusDays = !empty($flowConfig['free_trial_enabled'])
            ? (int) ($flowConfig['free_trial_days'] ?? 0)
            : 0;
        $durationDays = max(1, $planDays + $bonusDays);

        $deal = $this->dealPaymentService->createPendingDealWithCustomPricing(
            $client,
            $productId,
            (int) $price->id,
            (float) $price->price,
            $durationDays,
            $actorId,
            null
        );

        // The payment intentionally carries NO payment_data.duration_days: the
        // bonus lives only on deal.duration_days and flows through provisioning.
        $payment = $this->dealPaymentService->createLifecycleLinkPayment($deal, $client, [
            'flow' => $flow,
            'actor_id' => $actorId,
        ]);

        return [$deal, $payment];
    }

    // ---------------------------------------------------------------
    // Templates + variables
    // ---------------------------------------------------------------

    public function resolveTemplate(string $flow, int $platformId, $templateId = null): ?Template
    {
        if (is_numeric($templateId) && (int) $templateId > 0) {
            $template = Template::query()
                ->active()
                ->where('id', (int) $templateId)
                ->where(function (Builder $builder) use ($platformId) {
                    $builder->whereNull('platform_id')->orWhere('platform_id', $platformId);
                })
                ->first();
            if ($template) {
                return $template;
            }
        }

        $categories = self::FLOW_TEMPLATE_CATEGORIES[$flow] ?? [];
        if ($categories === []) {
            return null;
        }

        // Portable category preference (works on MariaDB prod AND sqlite tests).
        $categoryCases = collect($categories)
            ->map(fn ($category, $index) => "WHEN '" . $category . "' THEN " . $index)
            ->implode(' ');

        return Template::query()
            ->active()
            ->whereIn('category', $categories)
            ->where(function (Builder $builder) {
                $builder->where('channel', 'sms')->orWhereNull('channel');
            })
            ->where(function (Builder $builder) use ($platformId) {
                $builder->whereNull('platform_id')->orWhere('platform_id', $platformId);
            })
            ->orderByRaw('CASE WHEN platform_id IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw("CASE WHEN body LIKE '%payment_link%' THEN 0 ELSE 1 END")
            ->orderByRaw("CASE category {$categoryCases} ELSE 99 END")
            ->orderBy('id')
            ->first();
    }

    private function buildVariables(Client $client, ?Deal $deal, Payment $payment, string $paymentUrl): array
    {
        $extra = array_merge(
            $this->profileMetricsService->templateVariables($client),
            [
                'payment_link' => $paymentUrl,
                'amount' => number_format((float) $payment->amount),
                'currency' => (string) ($payment->currency ?: ($client->platform?->currency_code ?: 'KES')),
                // Defensive fallbacks so a send never hard-fails on a legacy
                // template that references these (e.g. Welcome / Payment
                // Confirmation copy). Empty strings still "resolve", so the
                // engine degrades gracefully instead of skipping on a missing
                // variable — the lifecycle templates themselves don't use them.
                'profile_url' => (string) ($client->wp_profile_permalink ?: ''),
                'support_chat_url' => (string) ($client->platform?->support_chat_url ?: ''),
                'transaction_reference' => (string) ($payment->transaction_reference ?: ''),
                'agent_name' => 'the team',
            ]
        );

        return $this->templateService->buildClientVariables($client, $deal, $extra);
    }

    // ---------------------------------------------------------------
    // Dispatch + recording
    // ---------------------------------------------------------------

    private function dispatchMessage(Client $client, string $body, string $channel, array $context): array
    {
        if ($channel !== 'whatsapp') {
            return $this->notificationService->sendSmsToClient($client, $body, [
                'purpose' => $context['purpose'],
                'phone_prefix' => $context['phone_prefix'],
            ]);
        }

        $dispatch = $this->messagingDispatcher->dispatch(
            MessageRecipient::fromClient($client),
            $body,
            'whatsapp_with_sms_fallback',
            [
                'message_type' => $context['purpose'],
                'platform_id' => (int) $client->platform_id,
                'actor_id' => $context['actor_id'] ?? null,
                'idempotency_key' => $context['purpose'] . '-' . $client->id . '-' . sha1($body . '|' . microtime(true)),
            ]
        );

        return [
            'success' => $dispatch->success,
            'status' => $dispatch->status,
            'provider' => $dispatch->channel,
            'channel' => $dispatch->channel,
            'provider_response' => $dispatch->smsResult['provider_response']
                ?? $dispatch->errorMessage
                ?? ($dispatch->success ? 'Message accepted by provider.' : 'Message could not be sent.'),
        ];
    }

    private function skip(string $flow, Client $client, string $reason): array
    {
        return $this->skipResult($flow, $client, $reason);
    }

    private function skipResult(string $flow, Client $client, string $reason): array
    {
        return [
            'status' => 'skipped',
            'flow' => $flow,
            'client_id' => (int) $client->id,
            'skip_reason' => $reason,
        ];
    }

    private function recordOutcome(string $flow, Client $client, string $status, array $details, int $actorId): array
    {
        $content = array_filter([
            'flow' => $flow,
            'status' => $status,
            'reference' => $details['reference'] ?? null,
            'template_id' => $details['template_id'] ?? null,
            'deal_id' => $details['deal_id'] ?? null,
            'payment_id' => $details['payment_id'] ?? null,
            'payment_url' => $details['payment_url'] ?? null,
            'channel' => $details['channel'] ?? null,
            'delivered_channel' => $details['delivered_channel'] ?? null,
            'source' => $details['source'] ?? 'automated',
            'error' => $details['error'] ?? null,
        ], static fn ($value) => $value !== null);

        TimelineEvent::create([
            'platform_id' => (int) $client->platform_id,
            'entity_type' => 'client',
            'entity_id' => (int) $client->id,
            'event_type' => self::TIMELINE_EVENT_TYPE,
            'actor_id' => $actorId,
            'content' => $content,
            'created_at' => now(),
        ]);

        if ($status === 'sent') {
            ClientNote::create([
                'client_id' => (int) $client->id,
                'author_id' => $actorId,
                'note_type' => 'system',
                'content' => sprintf('[Lifecycle] %s SMS: %s', ucfirst($flow), (string) ($details['body'] ?? '')),
                'follow_up_at' => null,
                'created_at' => now(),
            ]);

            // A successful reminder counts as contact, so the client naturally
            // moves out of the "New signups" conversion-queue bucket (which keys
            // on first_contact_at IS NULL). Never un-set an earlier first contact.
            $contactUpdates = ['last_contact_at' => now()];
            if ($client->first_contact_at === null) {
                $contactUpdates['first_contact_at'] = now();
            }
            $client->forceFill($contactUpdates)->saveQuietly();
        }

        $this->auditService->record([
            'platform_id' => (int) $client->platform_id,
            'actor_id' => $actorId,
            'action' => $status === 'sent' ? CrmAuditAction::LIFECYCLE_SMS_SENT : CrmAuditAction::LIFECYCLE_SMS_FAILED,
            'entity_type' => 'client',
            'entity_id' => (int) $client->id,
            'after_state' => $content,
            'reason' => sprintf('Lifecycle %s SMS (%s)', $flow, (string) ($details['source'] ?? 'automated')),
        ]);

        Log::info('Lifecycle SMS ' . $status, array_merge($content, [
            'client_id' => (int) $client->id,
            'platform_id' => (int) $client->platform_id,
        ]));

        return array_merge([
            'status' => $status,
            'flow' => $flow,
            'client_id' => (int) $client->id,
        ], $details);
    }

    // ---------------------------------------------------------------
    // Target discovery (sweeps + previews)
    // ---------------------------------------------------------------

    public function onboardingTargets(int $platformId, ?int $lookbackDays = null): Builder
    {
        $marketConfig = $this->settings->marketConfig($platformId);
        $lookback = $lookbackDays ?? (int) ($marketConfig['onboarding']['lookback_days'] ?? 14);

        return Client::query()
            ->with('platform')
            ->where('platform_id', $platformId)
            ->whereNull('closed_at')
            ->whereIn('signup_source', ['fast_signup', 'full_registration'])
            ->where('created_at', '>=', now()->subDays(max(1, $lookback)))
            ->whereNotNull('phone_normalized')
            ->orderByDesc('created_at');
    }

    public function reactivationTargets(int $platformId, array $windowsDays): Builder
    {
        $windows = array_values(array_filter(array_map('intval', $windowsDays), fn ($days) => $days > 0));
        if ($windows === []) {
            $windows = [7];
        }

        return Client::query()
            ->with('platform')
            ->where('platform_id', $platformId)
            ->whereNull('closed_at')
            ->whereNotNull('phone_normalized')
            ->whereNotNull('escort_expire')
            ->where(function (Builder $builder) use ($windows) {
                foreach ($windows as $days) {
                    $builder->orWhereBetween('escort_expire', [
                        now()->subDays($days)->startOfDay()->timestamp,
                        now()->subDays($days)->endOfDay()->timestamp,
                    ]);
                }
            })
            ->orderBy('escort_expire');
    }

    /** Which reactivation window (in days) a client's lapse falls into today. */
    public function reactivationWindowFor(Client $client, array $windowsDays): ?int
    {
        if (empty($client->escort_expire) || !is_numeric($client->escort_expire)) {
            return null;
        }

        foreach ($windowsDays as $days) {
            $days = (int) $days;
            if ($days <= 0) {
                continue;
            }

            $start = now()->subDays($days)->startOfDay()->timestamp;
            $end = now()->subDays($days)->endOfDay()->timestamp;
            if ((int) $client->escort_expire >= $start && (int) $client->escort_expire <= $end) {
                return $days;
            }
        }

        return null;
    }

    public function recoveryTargets(int $platformId, int $lookbackHours = 48): Builder
    {
        return Payment::query()
            ->with(['client.platform', 'deal'])
            ->where('platform_id', $platformId)
            ->where('status', 'failed')
            ->where('reconciliation_state', 'open')
            ->whereNull('manual_payment_bundle_id')
            ->where(function (Builder $builder) {
                $builder->whereNull('provider_key')->orWhere('provider_key', '!=', 'manual_confirmation');
            })
            ->whereNotNull('client_id')
            ->where('created_at', '>=', now()->subHours(max(1, $lookbackHours)))
            ->businessVisible()
            ->orderByDesc('created_at');
    }

    // ---------------------------------------------------------------
    // Preview (dry-run for the Lifecycle tab)
    // ---------------------------------------------------------------

    public function previewForMarket(string $flow, int $platformId, int $limit = 25): array
    {
        $platform = Platform::query()->findOrFail($platformId);
        $marketConfig = $this->settings->marketConfig($platformId);
        $rows = [];
        $sampleCount = 0;

        $evaluateClient = function (Client $client, array $options = []) use ($flow, &$rows, &$sampleCount, $marketConfig) {
            $evaluation = $this->evaluate($flow, $client, $options);
            $wouldSend = ($evaluation['status'] ?? '') === 'ok';

            $row = [
                'client_id' => (int) $client->id,
                'client_name' => (string) $client->name,
                'phone' => (string) $client->phone_normalized,
                'would_send' => $wouldSend,
                'skip_reason' => $wouldSend ? null : ($evaluation['skip_reason'] ?? null),
                'sample_body' => null,
            ];

            if ($wouldSend && $sampleCount < 5) {
                $template = $evaluation['template'];
                $variables = $this->templateService->buildClientVariables($client, null, array_merge(
                    $this->profileMetricsService->templateVariables($client),
                    [
                        'payment_link' => '[payment link]',
                        'amount' => '—',
                        'currency' => (string) ($client->platform?->currency_code ?: ''),
                    ]
                ));
                $rendered = $this->templateService->renderTemplate($template, $variables);
                $row['sample_body'] = $rendered['body'];
                $row['segments'] = (int) ceil(mb_strlen((string) $rendered['body']) / 160);
                $sampleCount++;
            }

            $rows[] = $row;
        };

        if ($flow === self::FLOW_RECOVERY) {
            $this->recoveryTargets($platformId)->limit($limit)->get()
                ->each(function (Payment $payment) use ($evaluateClient) {
                    if ($payment->client) {
                        $evaluateClient($payment->client, ['payment' => $payment]);
                    }
                });
        } elseif ($flow === self::FLOW_REACTIVATION) {
            $windows = (array) ($marketConfig['reactivation']['windows_days'] ?? [7]);
            $this->reactivationTargets($platformId, $windows)->limit($limit)->get()
                ->each(function (Client $client) use ($evaluateClient, $windows) {
                    $evaluateClient($client, [
                        'window_days' => $this->reactivationWindowFor($client, $windows) ?? 0,
                    ]);
                });
        } else {
            $this->onboardingTargets($platformId)->limit($limit)->get()
                ->each(fn (Client $client) => $evaluateClient($client));
        }

        $wouldSendCount = count(array_filter($rows, fn ($row) => $row['would_send']));

        return [
            'flow' => $flow,
            'platform_id' => $platformId,
            'platform_name' => (string) $platform->name,
            'capabilities' => $this->capabilitiesForPlatform($platform),
            'targets' => $rows,
            'total_targets' => count($rows),
            'would_send_count' => $wouldSendCount,
            'skipped_count' => count($rows) - $wouldSendCount,
        ];
    }

    /**
     * What a single client would receive for a flow, without sending. Powers the
     * "preview before send" affordance on the queues and client page.
     */
    public function previewForClient(string $flow, Client $client, array $options = []): array
    {
        $evaluation = $this->evaluate($flow, $client, $options);
        $wouldSend = ($evaluation['status'] ?? '') === 'ok';

        $template = $wouldSend
            ? $evaluation['template']
            : $this->resolveTemplate($flow, (int) $client->platform_id);

        $body = null;
        $segments = null;
        if ($template) {
            $variables = $this->templateService->buildClientVariables($client, null, array_merge(
                $this->profileMetricsService->templateVariables($client),
                [
                    'payment_link' => '[payment link]',
                    'amount' => '—',
                    'currency' => (string) ($client->platform?->currency_code ?: ''),
                    'profile_url' => (string) ($client->wp_profile_permalink ?: ''),
                    'support_chat_url' => (string) ($client->platform?->support_chat_url ?: ''),
                    'transaction_reference' => (string) (($options['payment'] ?? null)?->transaction_reference ?: ''),
                    'agent_name' => 'the team',
                ]
            ));
            $rendered = $this->templateService->renderTemplate($template, $variables);
            $body = rtrim((string) $rendered['body']);
            $segments = (int) max(1, ceil(mb_strlen($body) / 160));
        }

        return [
            'flow' => $flow,
            'client_id' => (int) $client->id,
            'would_send' => $wouldSend,
            'skip_reason' => $wouldSend ? null : ($evaluation['skip_reason'] ?? null),
            'template_id' => $template?->id,
            'template_title' => $template?->title,
            'body' => $body,
            'segments' => $segments,
        ];
    }

    /**
     * Per-client reminder telemetry for the client page: how many reminders
     * have gone out, the last one, a per-flow breakdown, and pause state.
     */
    public function reminderStats(Client $client): array
    {
        // Headline count comes from the client-scoped system notes each send
        // writes (one per successful lifecycle OR renewal reminder). NOTE: the
        // client_notes table has no reliable created_at, so timestamps for
        // "last sent" are derived from timeline events instead.
        $total = ClientNote::query()
            ->where('client_id', (int) $client->id)
            ->where('note_type', 'system')
            ->where(function (Builder $builder) {
                $builder->where('content', 'like', '[Lifecycle]%')
                    ->orWhere('content', 'like', '[RC%')
                    ->orWhere('content', 'like', '[Renewal %');
            })
            ->where('content', 'not like', '%Failed%')
            ->count();

        $lifecycleEvents = TimelineEvent::query()
            ->where('entity_type', 'client')
            ->where('entity_id', (int) $client->id)
            ->where('event_type', self::TIMELINE_EVENT_TYPE)
            ->get()
            ->filter(fn (TimelineEvent $event) => (($event->content['status'] ?? null) === 'sent'));

        $byFlow = $lifecycleEvents
            ->groupBy(fn (TimelineEvent $event) => (string) ($event->content['flow'] ?? 'other'))
            ->map(fn ($events) => $events->count())
            ->filter(fn ($count) => $count > 0)
            ->toArray();

        // Renewal reminders live on the deal (or client, for virtual renewals).
        $dealIds = $client->deals()->pluck('id')->filter()->all();
        $renewalTypes = [CrmAuditAction::RENEWAL_SMS_SENT, CrmAuditAction::RENEWAL_WHATSAPP_SENT];
        $renewalEvents = TimelineEvent::query()
            ->whereIn('event_type', $renewalTypes)
            ->where(function (Builder $builder) use ($client, $dealIds) {
                $builder->where(function (Builder $inner) use ($client) {
                    $inner->where('entity_type', 'client')->where('entity_id', (int) $client->id);
                });
                if ($dealIds !== []) {
                    $builder->orWhere(function (Builder $inner) use ($dealIds) {
                        $inner->where('entity_type', 'deal')->whereIn('entity_id', $dealIds);
                    });
                }
            })
            ->get();

        $lastSent = $lifecycleEvents->concat($renewalEvents)
            ->map(fn (TimelineEvent $event) => $event->created_at)
            ->filter()
            ->max();

        $pausedUntil = $client->reminders_paused_until;
        $isPaused = $pausedUntil && $pausedUntil->isFuture();

        return [
            'reminders_sent' => (int) $total,
            'last_sent_at' => $lastSent ? Carbon::parse($lastSent)->toIso8601String() : null,
            'by_flow' => $byFlow,
            'paused' => (bool) $isPaused,
            'paused_until' => $isPaused ? $pausedUntil->toIso8601String() : null,
        ];
    }

    public function inQuietHours(Platform $platform): bool
    {
        try {
            $timezone = trim((string) ($platform->timezone ?: '')) ?: 'Africa/Nairobi';
            $hour = (int) now($timezone)->format('G');
        } catch (\Throwable) {
            $hour = (int) now('Africa/Nairobi')->format('G');
        }

        return $hour < 8 || $hour >= 20;
    }

    /**
     * Renewal payment-link variables for one deal, used by RenewalService when
     * a renewal template embeds {{payment_link}}. Mints (or reuses) a pro-forma
     * deal on the client's current plan so the link auto-activates a genuine
     * renewal on payment.
     *
     * Returns [] when the template has no {{payment_link}} (nothing to do). When
     * the template DOES use the link but the market can't produce a tokenized one
     * (no PSP, flow off, no offer), it returns ['payment_link' => ''] so the
     * reminder still renders as a clean link-free nudge instead of failing on a
     * missing variable — the link fragment simply drops out of the copy.
     */
    public function renewalLinkVariables(?Deal $deal, Template $template, ?int $actorId = null): array
    {
        $usesLink = str_contains((string) $template->body, 'payment_link');
        if (!$usesLink) {
            return [];
        }

        $blank = ['payment_link' => ''];

        $client = $deal?->client;
        if (!$client) {
            return $blank;
        }

        $client->loadMissing('platform');
        $platform = $client->platform;
        if (!$platform || !$this->settings->flowEnabled((int) $platform->id, self::FLOW_RENEWAL)) {
            return $blank;
        }

        if (!$this->paymentLinkService->hasTokenizedProvider($platform)) {
            return $blank;
        }

        $marketConfig = $this->settings->marketConfig((int) $platform->id);

        // Offer = the client's current plan when we know it, else the market's
        // configured reactivation offer (covers legacy/virtual deals).
        $flowConfig = [
            'product_id' => $deal->product_id,
            'product_price_id' => $deal->product_price_id ?: $deal->base_product_price_id,
            'free_trial_enabled' => false,
            'free_trial_days' => 0,
        ];
        if (empty($flowConfig['product_id']) || empty($flowConfig['product_price_id'])) {
            $fallback = $marketConfig['reactivation'] ?? [];
            $flowConfig['product_id'] = $fallback['product_id'] ?? null;
            $flowConfig['product_price_id'] = $fallback['product_price_id'] ?? null;
        }
        if (empty($flowConfig['product_id']) || empty($flowConfig['product_price_id'])) {
            return $blank;
        }

        try {
            [, $payment] = $this->resolveProFormaDealAndPayment(
                $client,
                self::FLOW_RENEWAL,
                $flowConfig,
                $this->resolveActorId($actorId)
            );

            $link = $this->paymentLinkService->prepareTokenizedUrl($payment, [
                'notification_purpose' => 'lifecycle_renewal',
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Renewal payment link could not be prepared', [
                'deal_id' => $deal->id,
                'client_id' => (int) $client->id,
                'error' => $exception->getMessage(),
            ]);

            return $blank;
        }

        if (!($link['success'] ?? false)) {
            return $blank;
        }

        return ['payment_link' => (string) $link['payment_url']];
    }

    private function resolveActorId(?int $actorId): int
    {
        if ($actorId) {
            return $actorId;
        }

        $userId = User::query()->where('role', 'admin')->orderBy('id')->value('id');
        if ($userId) {
            return (int) $userId;
        }

        return (int) (User::query()->orderBy('id')->value('id') ?: 0);
    }
}
