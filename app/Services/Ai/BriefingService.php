<?php

namespace App\Services\Ai;

use App\Models\Briefing;
use App\Models\BriefingRecipient;
use App\Models\BriefingRun;
use App\Models\SmsLog;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\Seo\Exceptions\AllProvidersFailedException;
use Carbon\Carbon;
use Illuminate\Support\Carbon as SupportCarbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Orchestrates the weekly AI briefing pipeline end-to-end:
 *  1. Compute the previous Mon 00:00 -> Sun 23:59:59 window in the configured tz.
 *  2. Resolve opted-in recipients per audience and group them by market scope.
 *  3. Build a snapshot (MetricsSnapshotService) and an LLM digest/body per scope,
 *     degrading to a deterministic template when no provider succeeds.
 *  4. Fit a single GSM-7 SMS segment with a /b/{token} deep link per recipient.
 *  5. Persist briefing_runs / briefings / briefing_recipients and (live runs only)
 *     dispatch SMS via NotificationService, persisting an sms_logs row per send.
 *
 * Read/summarize only — it never mutates CRM business records. A --dry-run pass
 * computes and returns the exact SMS + rendered body without writing or sending.
 */
class BriefingService
{
    public function __construct(
        private readonly AiBriefingSettingsService $settings,
        private readonly MetricsSnapshotService $snapshots,
        private readonly AiGateway $gateway,
        private readonly GsmSmsLimiter $limiter,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  string  $audience  ceo|sales
     * @return array{status:string, audience:string, dry_run:bool, period:array, briefings:array, reason?:string, run_id?:int|null, cost_usd?:float}
     */
    public function run(string $audience, bool $dryRun = false, ?Carbon $date = null, ?int $triggeredBy = null): array
    {
        $audience = in_array($audience, ['ceo', 'sales'], true) ? $audience : 'sales';
        $window   = $this->resolvePeriod($date);

        // P1-8: a disabled feature must produce no AI call and no SMS when the
        // schedule fires. Manual dry-runs are still allowed so admins can preview.
        if (!$this->settings->enabled() && !$dryRun) {
            return [
                'status'    => 'skipped',
                'reason'    => 'disabled',
                'audience'  => $audience,
                'dry_run'   => false,
                'period'    => $this->periodPayload($window),
                'briefings' => [],
            ];
        }

        $scopes = $this->resolveScopes($audience);

        if ($scopes === []) {
            return [
                'status'    => 'skipped',
                'reason'    => 'no_recipients',
                'audience'  => $audience,
                'dry_run'   => $dryRun,
                'period'    => $this->periodPayload($window),
                'briefings' => [],
            ];
        }

        $run = null;
        if (!$dryRun) {
            $run = BriefingRun::create([
                'audience'     => $audience,
                'period'       => 'weekly',
                'period_start' => $window['utc_start'],
                'period_end'   => $window['utc_end'],
                'triggered_by' => $triggeredBy,
                'dry_run'      => false,
                'status'       => 'pending',
                'cost_usd'     => 0,
            ]);
        }

        $cap        = $this->settings->weeklyCostCapUsd();
        $runCost    = 0.0;
        $briefings  = [];

        foreach ($scopes as $scope) {
            $platformIds = $scope['platform_ids']; // null = org-wide
            $snapshot    = $this->snapshots->forScope(
                $platformIds,
                $window['snapshot_from'],
                $window['snapshot_to'],
            );

            $allowAi  = $this->settings->enabled() && $runCost < $cap;
            $content  = $this->buildContent($audience, $snapshot, $platformIds, $allowAi, $triggeredBy, $runCost);
            $runCost += $content['cost_usd'];

            $briefingPayload = [
                'scope'        => $scope,
                'sms_digest'   => $content['sms_digest'],
                'full_body'    => $content['full_body'],
                'used_ai'      => $content['used_ai'],
                'recipients'   => [],
            ];

            $briefing = null;
            if (!$dryRun) {
                $briefing = $this->persistBriefing($run, $audience, $window, $platformIds, $content);
            }

            foreach ($scope['recipients'] as $recipient) {
                $assembled = $this->assembleRecipient(
                    $audience,
                    $window,
                    $platformIds,
                    $content,
                    $recipient,
                    $briefing,
                    $dryRun,
                );
                $briefingPayload['recipients'][] = $assembled;
            }

            $briefings[] = $briefingPayload;
        }

        if ($run) {
            $run->update([
                'status'   => 'completed',
                'cost_usd' => round($runCost, 6),
            ]);
        }

        return [
            'status'    => 'completed',
            'audience'  => $audience,
            'dry_run'   => $dryRun,
            'period'    => $this->periodPayload($window),
            'run_id'    => $run?->id,
            'cost_usd'  => round($runCost, 6),
            'briefings' => $briefings,
        ];
    }

    /**
     * Previous calendar week (Mon 00:00 -> Sun 23:59:59) in the configured tz.
     * Returns both the local-tz Carbons used by the snapshot query (which mirror
     * the dashboard's wall-clock date semantics) and UTC instants for storage.
     *
     * @return array{local_start:Carbon, local_end:Carbon, utc_start:Carbon, utc_end:Carbon, snapshot_from:Carbon, snapshot_to:Carbon}
     */
    private function resolvePeriod(?Carbon $date): array
    {
        $tz  = $this->settings->timezone();
        $now = ($date ? $date->copy() : Carbon::now($tz))->setTimezone($tz);

        $localStart = $now->copy()->startOfWeek(Carbon::MONDAY)->subWeek();
        $localEnd   = $localStart->copy()->endOfWeek(Carbon::SUNDAY);

        return [
            'local_start'   => $localStart,
            'local_end'     => $localEnd,
            'utc_start'     => $localStart->copy()->utc(),
            'utc_end'       => $localEnd->copy()->utc(),
            'snapshot_from' => $localStart->copy(),
            'snapshot_to'   => $localEnd->copy(),
        ];
    }

    private function periodPayload(array $window): array
    {
        return [
            'timezone'   => $this->settings->timezone(),
            'from'       => $window['local_start']->toDateString(),
            'to'         => $window['local_end']->toDateString(),
            'utc_start'  => $window['utc_start']->toIso8601String(),
            'utc_end'    => $window['utc_end']->toIso8601String(),
        ];
    }

    /**
     * Resolve distinct briefing scopes for an audience, each carrying its opted-in
     * recipients. CEO is a single org-wide scope. Sales scopes are derived per
     * recipient (explicit scope_platform_ids, else the linked user's assigned
     * markets, else org-wide) and grouped by scope hash so identical scopes share
     * one briefing.
     *
     * @return array<int, array{platform_ids:int[]|null, scope_hash:string, recipients:array<int,array>}>
     */
    private function resolveScopes(string $audience): array
    {
        $recipients = $this->settings->activeRecipientsForAudience($audience);

        if ($recipients === []) {
            return [];
        }

        if ($audience === 'ceo') {
            return [[
                'platform_ids' => null,
                'scope_hash'   => Briefing::scopeHashFor(null),
                'recipients'   => array_map(fn ($r) => $this->hydrateRecipient($r, null), $recipients),
            ]];
        }

        $userIds = array_values(array_unique(array_map(fn ($r) => (int) $r['user_id'], $recipients)));
        $users   = User::query()->whereIn('id', $userIds)->get()->keyBy('id');

        $grouped = [];
        foreach ($recipients as $r) {
            $platformIds = $this->scopeForRecipient($r, $users->get((int) $r['user_id']));
            $hash        = Briefing::scopeHashFor($platformIds);

            if (!isset($grouped[$hash])) {
                $grouped[$hash] = [
                    'platform_ids' => $platformIds,
                    'scope_hash'   => $hash,
                    'recipients'   => [],
                ];
            }

            $grouped[$hash]['recipients'][] = $this->hydrateRecipient($r, $platformIds);
        }

        return array_values($grouped);
    }

    /** @return int[]|null */
    private function scopeForRecipient(array $recipient, ?User $user): ?array
    {
        $explicit = $recipient['scope_platform_ids'] ?? null;
        if (is_array($explicit) && $explicit !== []) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $explicit), fn ($id) => $id > 0)));

            return $ids === [] ? null : $ids;
        }

        $assigned = $user?->assignedMarketIds() ?? [];

        return $assigned === [] ? null : array_values(array_unique($assigned));
    }

    /** @return array{user_id:int, name:?string, phone:?string, scope_platform_ids:?array} */
    private function hydrateRecipient(array $r, ?array $platformIds): array
    {
        return [
            'user_id'            => (int) $r['user_id'],
            'name'               => $r['name'] ?? null,
            'phone'              => $r['phone'] ?? null,
            'scope_platform_ids' => $platformIds,
        ];
    }

    /**
     * Build the SMS digest + structured body for one scope, calling the LLM when
     * allowed and falling back to a deterministic template otherwise.
     *
     * @return array{sms_digest:string, full_body:array, used_ai:bool, cost_usd:float}
     */
    private function buildContent(string $audience, array $snapshot, ?array $platformIds, bool $allowAi, ?int $userId, float $runCostSoFar): array
    {
        $template = $this->templateContent($audience, $snapshot);

        if (!$allowAi) {
            return [
                'sms_digest' => $template['sms_digest'],
                'full_body'  => $template['full_body'],
                'used_ai'    => false,
                'cost_usd'   => 0.0,
            ];
        }

        try {
            $system = $this->systemPrompt($audience);
            $user   = json_encode([
                'audience' => $audience,
                'snapshot' => $snapshot,
            ], JSON_UNESCAPED_SLASHES);

            $result  = $this->gateway->generate('briefing_' . $audience, $system, (string) $user, [
                'user_id'    => $userId,
                'max_tokens' => 900,
            ]);

            $parsed = $this->parseAiContent($result->text());
            if ($parsed === null) {
                throw new \RuntimeException('AI briefing response was not parseable JSON.');
            }

            $cost = (float) ($result->interaction->est_cost_usd ?? 0);

            return [
                'sms_digest' => $parsed['sms_digest'] !== '' ? $parsed['sms_digest'] : $template['sms_digest'],
                'full_body'  => $parsed['full_body'],
                'used_ai'    => true,
                'cost_usd'   => $cost,
            ];
        } catch (AllProvidersFailedException $e) {
            Log::info('briefing.ai_unavailable_template_fallback', ['audience' => $audience, 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::warning('briefing.ai_failed_template_fallback', ['audience' => $audience, 'error' => $e->getMessage()]);
        }

        return [
            'sms_digest' => $template['sms_digest'],
            'full_body'  => $template['full_body'],
            'used_ai'    => false,
            'cost_usd'   => 0.0,
        ];
    }

    /** @return array{sms_digest:string, full_body:array}|null */
    private function parseAiContent(string $text): ?array
    {
        $text = trim($text);

        // Strip markdown code fences if the model wrapped the JSON.
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```[a-zA-Z]*\n?/', '', $text);
            $text = preg_replace('/\n?```$/', '', (string) $text);
            $text = trim((string) $text);
        }

        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            return null;
        }

        $digest = trim((string) ($decoded['sms_digest'] ?? ''));
        $body   = $decoded['full_body'] ?? null;

        if (!is_array($body)) {
            $body = ['narrative' => is_string($body) ? $body : ''];
        }

        return [
            'sms_digest' => $digest,
            'full_body'  => $body,
        ];
    }

    private function systemPrompt(string $audience): string
    {
        $who = $audience === 'ceo'
            ? 'the CEO of a multi-market subscription business'
            : 'a sales lead responsible for specific markets';

        return <<<PROMPT
You are an analyst writing a concise weekly performance briefing for {$who}.
You will receive a JSON snapshot of already-aggregated, currency-normalized metrics.
Use ONLY the numbers provided — never invent figures. Do not give instructions to
take destructive or write actions; this is an informational summary only.

Respond with STRICT JSON (no markdown, no prose outside JSON) shaped exactly as:
{
  "sms_digest": "<=150 chars, plain ASCII, the single most important takeaway plus one number>",
  "full_body": {
    "headline": "one-line summary",
    "highlights": ["3-5 short bullet strings citing concrete numbers"],
    "watch_items": ["1-3 short risk/attention bullets, e.g. renewals at risk"],
    "narrative": "2-4 sentence plain-language explanation of the week"
  }
}
Keep sms_digest short enough to fit one SMS segment alongside a link.
PROMPT;
    }

    /** @return array{sms_digest:string, full_body:array} */
    private function templateContent(string $audience, array $snapshot): array
    {
        $currency = $snapshot['revenue']['normalized_currency'] ?? 'USD';
        $total    = (float) ($snapshot['revenue']['normalized_total'] ?? 0);
        $delta    = $snapshot['revenue']['delta_percent'] ?? null;
        $subs     = (int) ($snapshot['active_subscribers']['count'] ?? 0);
        $risk     = (int) ($snapshot['renewals']['risk'] ?? 0);
        $pending  = (int) ($snapshot['renewals']['pending'] ?? 0);

        $deltaStr = $delta === null ? '' : sprintf(' (%s%.1f%% WoW)', $delta >= 0 ? '+' : '', $delta);
        $money    = $currency . ' ' . number_format($total, 0);

        $digest = sprintf(
            'Weekly: rev %s%s, %d active subs, %d renewals at risk.',
            $money,
            $deltaStr,
            $subs,
            $risk,
        );

        $highlights = [
            sprintf('Revenue %s%s across %d payments', $money, $deltaStr, (int) ($snapshot['revenue']['payments_count'] ?? 0)),
            sprintf('%d active subscribers', $subs),
            sprintf('%d renewals pending, %d at risk', $pending, $risk),
        ];

        foreach (array_slice($snapshot['top_markets'] ?? [], 0, 3) as $market) {
            $highlights[] = sprintf(
                'Top market %s: %s %s',
                $market['name'] ?? 'Unknown',
                $market['normalized_currency'] ?? $currency,
                number_format((float) ($market['normalized_total'] ?? 0), 0),
            );
        }

        $watch = [];
        if ($risk > 0) {
            $watch[] = sprintf('%d renewals at risk of lapsing', $risk);
        }
        if ($delta !== null && $delta < 0) {
            $watch[] = sprintf('Revenue down %.1f%% vs prior week', abs((float) $delta));
        }

        return [
            'sms_digest' => $digest,
            'full_body'  => [
                'headline'    => sprintf('Weekly briefing: %s%s', $money, $deltaStr),
                'highlights'  => $highlights,
                'watch_items' => $watch,
                'narrative'   => sprintf(
                    'Over the period the business recorded %s in normalized revenue%s, with %d active subscribers and %d renewals at risk.',
                    $money,
                    $deltaStr,
                    $subs,
                    $risk,
                ),
                'generated'   => 'template',
            ],
        ];
    }

    private function persistBriefing(BriefingRun $run, string $audience, array $window, ?array $platformIds, array $content): Briefing
    {
        $hash = Briefing::scopeHashFor($platformIds);

        return Briefing::updateOrCreate(
            [
                'audience'     => $audience,
                'period'       => 'weekly',
                'period_start' => $window['utc_start'],
                'scope_hash'   => $hash,
            ],
            [
                'briefing_run_id'    => $run->id,
                'scope_platform_ids' => $platformIds,
                'period_end'         => $window['utc_end'],
                'summary_sms'        => $content['sms_digest'],
                'body_full'          => json_encode($content['full_body'], JSON_UNESCAPED_SLASHES),
                'generated_by'       => $run->triggered_by,
            ],
        );
    }

    /**
     * Assemble the per-recipient SMS (single GSM-7 segment + deep link) and, for
     * live runs, persist the recipient row + dispatch + sms_logs. Dry-runs mutate
     * nothing and return the exact text that would have been sent.
     */
    private function assembleRecipient(
        string $audience,
        array $window,
        ?array $platformIds,
        array $content,
        array $recipient,
        ?Briefing $briefing,
        bool $dryRun,
    ): array {
        $token = Str::random(32);
        $link  = $this->buildLink($token);
        $fit   = $this->limiter->fitWithLink($content['sms_digest'], $link);

        $base = [
            'user_id'        => $recipient['user_id'],
            'name'           => $recipient['name'],
            'phone'          => $recipient['phone'],
            'sms_text'       => $fit['text'],
            'sms_char_count' => $fit['char_count'],
            'sms_segments'   => $fit['segments'],
            'link'           => $link,
        ];

        if ($dryRun || !$briefing) {
            return $base + ['delivery_status' => 'preview', 'share_token' => null];
        }

        $expiresAt = SupportCarbon::now()->addDays($this->settings->linkTtlDays());

        $row = BriefingRecipient::create([
            'briefing_id'        => $briefing->id,
            'user_id'            => $recipient['user_id'],
            'name'               => $recipient['name'],
            'phone'              => $recipient['phone'],
            'audience'           => $audience,
            'scope_platform_ids' => $platformIds,
            'share_token'        => $token,
            'expires_at'         => $expiresAt,
            'sms_text'           => $fit['text'],
            'sms_char_count'     => $fit['char_count'],
            'sms_segments'       => $fit['segments'],
            'delivery_status'    => 'pending',
            'opt_out_snapshot'   => false,
        ]);

        $delivery = $this->dispatch($recipient, $platformIds, $fit['text'], $row->id);

        $row->update([
            'delivery_status' => $delivery['status'],
            'sms_log_id'      => $delivery['sms_log_id'],
        ]);

        return $base + [
            'delivery_status' => $delivery['status'],
            'share_token'     => $token,
            'recipient_id'    => $row->id,
        ];
    }

    /** @return array{status:string, sms_log_id:?int} */
    private function dispatch(array $recipient, ?array $platformIds, string $text, int $recipientId): array
    {
        $phone = $recipient['phone'] ?? null;

        if (!$phone) {
            $log = $this->logSms(null, $text, 'failed', 'Missing recipient phone', $recipientId, null);

            return ['status' => 'failed', 'sms_log_id' => $log->id];
        }

        $context = ['briefing_recipient_id' => $recipientId];
        if ($override = $this->settings->smsProviderOverride()) {
            $context['sms_provider'] = $override;
        }
        if (is_array($platformIds) && count($platformIds) === 1) {
            $context['platform_id'] = $platformIds[0];
        }

        $result = $this->notifications->sendSms($phone, $text, $context);

        $status = match (true) {
            ($result['status'] ?? null) === 'disabled' => 'disabled',
            (bool) ($result['success'] ?? false)       => 'sent',
            default                                     => 'failed',
        };

        $log = $this->logSms(
            $result['phone'] ?? $phone,
            $text,
            $status === 'sent' ? 'sent' : ($status === 'disabled' ? 'disabled' : 'failed'),
            $this->stringifyResponse($result['provider_response'] ?? null),
            $recipientId,
            $result['result_code'] ?? null,
        );

        return ['status' => $status, 'sms_log_id' => $log->id];
    }

    private function logSms(?string $phone, string $message, string $status, ?string $response, int $recipientId, ?string $resultCode): SmsLog
    {
        return SmsLog::create([
            'phone'                  => $phone,
            'message'                => $message,
            'status'                 => $status,
            'response'               => $response,
            'payment_id'             => null,
            'briefing_recipient_id'  => $recipientId,
            'sent_at'                => SupportCarbon::now(),
            'result_code'            => $resultCode,
        ]);
    }

    private function stringifyResponse(mixed $response): ?string
    {
        if ($response === null) {
            return null;
        }

        if (is_string($response)) {
            return mb_substr($response, 0, 1000);
        }

        return mb_substr((string) json_encode($response, JSON_UNESCAPED_SLASHES), 0, 1000);
    }

    private function buildLink(string $token): string
    {
        $base = $this->settings->baseUrl();

        return ($base !== '' ? $base : rtrim((string) config('app.url'), '/')) . '/b/' . $token;
    }
}
