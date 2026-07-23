<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\SmsLog;
use App\Models\Template;
use App\Services\AuditService;
use App\Services\ClientProfileMetricsService;
use App\Services\LifecycleSmsService;
use App\Services\LifecycleSmsSettingsService;
use App\Services\MarketAuthorizationService;
use App\Support\CrmAuditAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class LifecycleSmsController extends Controller
{
    public function __construct(
        private readonly LifecycleSmsSettingsService $settings,
        private readonly LifecycleSmsService $lifecycleSmsService,
        private readonly ClientProfileMetricsService $profileMetricsService,
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly AuditService $auditService
    ) {
    }

    public function config(Request $request)
    {
        $this->marketAuthorizationService->ensureManager($request->user());

        $config = $this->settings->currentConfig();
        $platforms = Platform::query()->orderBy('name')->get(['id', 'name', 'currency_code', 'timezone']);

        $markets = $platforms->map(function (Platform $platform) use ($config) {
            return [
                'platform_id' => (int) $platform->id,
                'platform_name' => (string) $platform->name,
                'currency_code' => (string) ($platform->currency_code ?: ''),
                'overrides' => $config['markets'][(string) $platform->id] ?? null,
                'effective' => $this->settings->marketConfig((int) $platform->id),
                'capabilities' => $this->lifecycleSmsService->capabilitiesForPlatform($platform),
                'metrics' => $this->profileMetricsService->freshnessForPlatform((int) $platform->id),
            ];
        })->values();

        $templates = Template::query()
            ->active()
            ->whereIn('category', ['welcome', 'new_signup', 'payment', 'win_back', 'renewal'])
            ->orderBy('category')
            ->orderBy('title')
            ->get(['id', 'title', 'category', 'platform_id', 'channel', 'body']);

        $products = Product::query()
            ->where('is_active', true)
            ->where('is_archived', false)
            ->with(['prices' => function ($query) {
                $query->where('is_active', true)->orderBy('sort_order')->orderBy('duration_days');
            }])
            ->orderBy('platform_id')
            ->orderBy('sort_order')
            ->get(['id', 'platform_id', 'name', 'display_name', 'tier', 'currency'])
            ->map(fn (Product $product) => [
                'id' => (int) $product->id,
                'platform_id' => (int) $product->platform_id,
                'name' => (string) ($product->display_name ?: $product->name),
                'tier' => (string) ($product->tier ?: ''),
                'prices' => $product->prices->map(fn ($price) => [
                    'id' => (int) $price->id,
                    'label' => (string) ($price->duration_label ?: ($price->duration_days . ' days')),
                    'duration_days' => (int) $price->duration_days,
                    'price' => (float) $price->price,
                    'currency' => (string) $price->currency,
                ])->values(),
            ])->values();

        return response()->json([
            'enabled' => (bool) $config['enabled'],
            'defaults' => $config['defaults'],
            'markets' => $markets,
            'templates' => $templates,
            'products' => $products,
            'flows' => LifecycleSmsSettingsService::FLOWS,
        ]);
    }

    public function updateConfig(Request $request)
    {
        $this->marketAuthorizationService->ensureRole(
            $request->user(),
            [MarketAuthorizationService::ROLE_ADMIN],
            'Only admin users can change lifecycle SMS settings.'
        );

        $validated = $request->validate([
            'enabled' => 'sometimes|boolean',
            'defaults' => 'sometimes|array',
            'markets' => 'sometimes|array',
            'reason' => 'required|string|max:500',
        ]);

        $before = $this->settings->currentConfig();
        $after = $this->settings->saveConfig($validated, (int) $request->user()->id);

        \App\Helpers\LogHelper::record($request->user(), CrmAuditAction::LIFECYCLE_SMS_CONFIG_UPDATE, $request, [
            'before' => ['enabled' => $before['enabled'], 'market_count' => count($before['markets'] ?? [])],
            'after' => ['enabled' => $after['enabled'], 'market_count' => count($after['markets'] ?? [])],
            'reason' => $validated['reason'],
        ]);

        return response()->json([
            'message' => 'Lifecycle SMS settings saved.',
            'enabled' => (bool) $after['enabled'],
            'defaults' => $after['defaults'],
        ]);
    }

    public function preview(Request $request)
    {
        $this->marketAuthorizationService->ensureManager($request->user());

        $validated = $request->validate([
            'flow' => 'required|string|in:onboarding,recovery,reactivation',
            'platform_id' => 'required|integer|exists:platforms,id',
            'limit' => 'sometimes|integer|min:1|max:100',
        ]);

        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $validated['platform_id']);

        return response()->json($this->lifecycleSmsService->previewForMarket(
            (string) $validated['flow'],
            (int) $validated['platform_id'],
            (int) ($validated['limit'] ?? 25)
        ));
    }

    public function testSend(Request $request)
    {
        $this->marketAuthorizationService->ensureManager($request->user());

        $validated = $request->validate([
            'flow' => 'required|string|in:onboarding,recovery,reactivation,renewal',
            'platform_id' => 'required|integer|exists:platforms,id',
            'phone' => 'required|string|max:20',
            'template_id' => 'nullable|integer',
        ]);

        $platformId = (int) $validated['platform_id'];
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), $platformId);

        $template = $this->lifecycleSmsService->resolveTemplate(
            (string) $validated['flow'],
            $platformId,
            $validated['template_id'] ?? null
        );

        if (!$template) {
            return response()->json(['message' => 'No template available for this flow.'], 422);
        }

        // Render with a real client's variables when one exists so the test
        // reads like production copy; link is a placeholder — no deal is minted.
        $sampleClient = Client::query()
            ->where('platform_id', $platformId)
            ->whereNotNull('name')
            ->latest('id')
            ->first();

        $templateService = app(\App\Services\TemplateService::class);
        $variables = $sampleClient
            ? $templateService->buildClientVariables($sampleClient, null, array_merge(
                $this->profileMetricsService->templateVariables($sampleClient),
                ['payment_link' => 'https://example.com/pay/TEST', 'amount' => '1,000', 'currency' => 'KES']
            ))
            : ['payment_link' => 'https://example.com/pay/TEST'];

        $rendered = $templateService->renderTemplate($template, $variables);
        if (!empty($rendered['missing'])) {
            return response()->json([
                'message' => 'Template has unresolved variables: ' . implode(', ', $rendered['missing']),
                'missing' => $rendered['missing'],
            ], 422);
        }

        $result = app(\App\Services\NotificationService::class)->sendSms(
            (string) $validated['phone'],
            '[TEST] ' . $rendered['body'],
            [
                'platform_id' => $platformId,
                'purpose' => 'lifecycle_test',
            ]
        );

        return response()->json([
            'success' => (bool) ($result['success'] ?? false),
            'status' => $result['status'] ?? null,
            'provider' => $result['provider'] ?? null,
            'provider_response' => $result['provider_response'] ?? null,
            'body' => $rendered['body'],
            'segments' => (int) ceil(mb_strlen((string) $rendered['body']) / 160),
        ]);
    }

    public function activity(Request $request)
    {
        $this->marketAuthorizationService->ensureManager($request->user());

        $platformId = $request->query('platform_id');
        $flow = trim((string) $request->query('flow', ''));

        $query = SmsLog::query()
            ->where('purpose', 'like', 'lifecycle_%')
            ->when($platformId, fn ($builder) => $builder->where('platform_id', (int) $platformId))
            ->when($flow !== '', fn ($builder) => $builder->where('purpose', 'lifecycle_' . $flow))
            ->orderByDesc('sent_at');

        $logs = $query->paginate(min(100, max(10, (int) $request->query('per_page', 25))));

        return response()->json([
            'data' => collect($logs->items())->map(fn (SmsLog $log) => [
                'id' => (int) $log->id,
                'phone' => (string) $log->phone,
                'message' => (string) $log->message,
                'status' => (string) $log->status,
                'provider' => (string) ($log->provider ?: ''),
                'purpose' => (string) ($log->purpose ?: ''),
                'flow' => str_replace('lifecycle_', '', (string) ($log->purpose ?: '')),
                'platform_id' => $log->platform_id ? (int) $log->platform_id : null,
                'fallback_used' => (bool) $log->fallback_used,
                'sent_at' => optional($log->sent_at)?->toIso8601String(),
            ])->values(),
            'total' => $logs->total(),
            'page' => $logs->currentPage(),
            'per_page' => $logs->perPage(),
        ]);
    }

    public function run(Request $request)
    {
        $this->marketAuthorizationService->ensureRole(
            $request->user(),
            [MarketAuthorizationService::ROLE_ADMIN],
            'Only admin users can trigger a lifecycle SMS run.'
        );

        $validated = $request->validate([
            'flow' => 'required|string|in:onboarding,recovery,reactivation,all',
            'platform_id' => 'nullable|integer|exists:platforms,id',
            'dry_run' => 'sometimes|boolean',
        ]);

        $parameters = [
            '--flow' => (string) $validated['flow'],
        ];
        if (!empty($validated['platform_id'])) {
            $parameters['--platform'] = (int) $validated['platform_id'];
        }
        if (!empty($validated['dry_run'])) {
            $parameters['--dry-run'] = true;
        }

        Artisan::call('crm:run-lifecycle-sms', $parameters);

        return response()->json([
            'message' => !empty($validated['dry_run']) ? 'Dry run completed.' : 'Lifecycle run completed.',
            'output' => Artisan::output(),
        ]);
    }

    /** What one client would receive for a flow — no send (preview before send). */
    public function previewClient(Request $request, Client $client)
    {
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $client->platform_id);

        $validated = $request->validate([
            'flow' => 'required|string|in:onboarding,recovery,reactivation,renewal',
        ]);

        return response()->json($this->lifecycleSmsService->previewForClient((string) $validated['flow'], $client));
    }

    /** Reminder telemetry for the client page (count, last send, per-flow, paused). */
    public function stats(Request $request, Client $client)
    {
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $client->platform_id);

        return response()->json($this->lifecycleSmsService->reminderStats($client));
    }

    /** Pause / resume all automated + manual outreach for one client. */
    public function setPause(Request $request, Client $client)
    {
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $client->platform_id);

        $validated = $request->validate([
            'paused' => 'required|boolean',
            'days' => 'nullable|integer|min:1|max:365',
        ]);

        if ($validated['paused']) {
            $until = !empty($validated['days'])
                ? now()->addDays((int) $validated['days'])
                : now()->addYears(5); // indefinite
            $client->forceFill(['reminders_paused_until' => $until])->save();
        } else {
            $client->forceFill(['reminders_paused_until' => null])->save();
        }

        $this->auditService->record([
            'platform_id' => (int) $client->platform_id,
            'actor_id' => (int) $request->user()->id,
            'action' => CrmAuditAction::LIFECYCLE_SMS_CONFIG_UPDATE,
            'entity_type' => 'client',
            'entity_id' => (int) $client->id,
            'after_state' => ['reminders_paused_until' => optional($client->reminders_paused_until)?->toIso8601String()],
            'reason' => $validated['paused'] ? 'Paused client reminders' : 'Resumed client reminders',
        ]);

        return response()->json($this->lifecycleSmsService->reminderStats($client->fresh()));
    }

    /**
     * Bulk lifecycle send across many clients (conversion-queue multi-select).
     * Each client routes through the same gated service, so dedup / state / quiet
     * hours / pause all apply per client. Returns per-client outcomes + a summary.
     */
    public function bulkSend(Request $request)
    {
        $validated = $request->validate([
            'flow' => 'required|string|in:onboarding,reactivation',
            'client_ids' => 'required|array|min:1|max:200',
            'client_ids.*' => 'integer',
        ]);

        $clients = Client::query()
            ->with('platform')
            ->whereIn('id', $validated['client_ids'])
            ->get();

        $results = [];
        $summary = ['sent' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($clients as $client) {
            if (!$this->marketAuthorizationService->userCanAccessPlatform($request->user(), (int) $client->platform_id)) {
                $results[] = ['client_id' => (int) $client->id, 'status' => 'skipped', 'skip_reason' => 'no_access'];
                $summary['skipped']++;
                continue;
            }

            $result = $this->lifecycleSmsService->send((string) $validated['flow'], $client, [
                'actor_id' => (int) $request->user()->id,
                'source' => 'manual',
            ]);

            $status = (string) ($result['status'] ?? 'failed');
            $bucket = $status === 'sent' ? 'sent' : ($status === 'skipped' ? 'skipped' : 'failed');
            $summary[$bucket]++;
            $results[] = [
                'client_id' => (int) $client->id,
                'status' => $status,
                'skip_reason' => $result['skip_reason'] ?? null,
            ];
        }

        return response()->json([
            'message' => sprintf('%d sent, %d skipped, %d failed.', $summary['sent'], $summary['skipped'], $summary['failed']),
            'summary' => $summary,
            'results' => $results,
        ]);
    }

    /**
     * Manual lifecycle send for one client (conversion-queue cockpit). Routes
     * through the SAME service, so dedup/state gates prevent double-sends.
     */
    public function sendToClient(Request $request, Client $client)
    {
        $validated = $request->validate([
            'flow' => 'required|string|in:onboarding,reactivation',
        ]);

        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $client->platform_id);

        $result = $this->lifecycleSmsService->send((string) $validated['flow'], $client, [
            'actor_id' => (int) $request->user()->id,
            'source' => 'manual',
        ]);

        return $this->respondWithSendResult($result);
    }

    /** Manual recovery send for one failed payment (conversion-queue action). */
    public function sendRecovery(Request $request, Payment $payment)
    {
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), (int) $payment->platform_id);

        $payment->loadMissing(['client.platform', 'deal']);
        if (!$payment->client) {
            return response()->json(['message' => 'Payment is not matched to a client.'], 422);
        }

        $result = $this->lifecycleSmsService->send(LifecycleSmsService::FLOW_RECOVERY, $payment->client, [
            'payment' => $payment,
            'actor_id' => (int) $request->user()->id,
            'source' => 'manual',
        ]);

        return $this->respondWithSendResult($result);
    }

    private function respondWithSendResult(array $result)
    {
        $status = (string) ($result['status'] ?? 'failed');

        if ($status === 'sent') {
            return response()->json([
                'message' => 'Lifecycle SMS sent.',
                'result' => $result,
            ]);
        }

        if ($status === 'skipped') {
            return response()->json([
                'message' => 'Send skipped: ' . $this->humanSkipReason((string) ($result['skip_reason'] ?? 'unknown')),
                'result' => $result,
            ], 409);
        }

        return response()->json([
            'message' => 'Lifecycle SMS failed: ' . (string) ($result['error'] ?? ($result['provider_response'] ?? 'unknown error')),
            'result' => $result,
        ], 502);
    }

    private function humanSkipReason(string $reason): string
    {
        return match ($reason) {
            'disabled_global' => 'lifecycle SMS is globally disabled.',
            'market_sms_disabled' => 'lifecycle SMS is disabled for this market.',
            'flow_disabled' => 'this flow is disabled for this market.',
            'market_no_psp' => 'this market has no tokenized payment provider.',
            'client_already_active' => 'the client already has an active subscription.',
            'already_sent' => 'this SMS was already sent for this trigger.',
            'rate_capped' => 'the client hit the lifecycle SMS rate cap.',
            'manual_payment' => 'this is a manual payment awaiting review.',
            'test_payment' => 'this is a test/sandbox payment.',
            'no_template' => 'no template is configured for this flow.',
            'no_offer_configured' => 'no onboarding/reactivation offer plan is configured for this market.',
            'quiet_hours' => 'the market is in quiet hours.',
            'missing_phone' => 'the client has no phone number.',
            'sms_dispatch_disabled' => 'SMS dispatch is disabled globally.',
            default => $reason,
        };
    }
}
