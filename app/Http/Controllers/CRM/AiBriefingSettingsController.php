<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Jobs\RunAiBriefingJob;
use App\Models\AiInteraction;
use App\Models\Briefing;
use App\Models\BriefingRecipient;
use App\Models\BriefingRun;
use App\Models\Platform;
use App\Models\User;
use App\Services\Ai\AiBriefingSettingsService;
use App\Services\Ai\AiInsightsSettingsService;
use App\Services\Ai\BriefingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin workspace for AI briefings + insights configuration.
 *
 * Read/configure only. The single mutating-adjacent action it exposes is a
 * manual --dry-run preview of a briefing, which sends nothing and persists no
 * recipient state (see BriefingService::run with dryRun=true).
 */
class AiBriefingSettingsController extends Controller
{
    public function __construct(
        private readonly AiBriefingSettingsService $briefingSettings,
        private readonly AiInsightsSettingsService $insightsSettings,
        private readonly BriefingService $briefings,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'briefings' => $this->briefingSettings->settings(),
            'insights' => $this->insightsSettings->settings(),
            'recipients' => $this->briefingSettings->recipients(),
            'users' => $this->eligibleUsers(),
            'platforms' => Platform::query()->orderBy('name')->get(['id', 'name', 'country'])->toArray(),
            'recent_runs' => $this->recentRuns(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'admin_override' => ['sometimes', 'boolean'],
            'weekly_cost_cap_usd' => ['sometimes', 'numeric', 'min:0'],
            'link_ttl_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'base_url' => ['sometimes', 'string', 'max:255'],
            'sms_provider_override' => ['sometimes', 'nullable', 'string', 'max:64'],
            'schedule' => ['sometimes', 'array'],
            'schedule.ceo_enabled' => ['sometimes', 'boolean'],
            'schedule.sales_enabled' => ['sometimes', 'boolean'],
            'schedule.ceo_time' => ['sometimes', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'schedule.sales_time' => ['sometimes', 'string', 'regex:/^\d{2}:\d{2}$/'],
        ]);

        $settings = $this->briefingSettings->save($data, $request->user()?->id);

        return response()->json(['briefings' => $settings]);
    }

    public function updateInsights(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'allowed_roles' => ['sometimes', 'array'],
            'allowed_roles.*' => ['string', 'max:32'],
            'sources' => ['sometimes', 'array'],
            'default_row_limit' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'max_row_limit' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'sql_timeout_seconds' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'show_generated_sql' => ['sometimes', 'boolean'],
            'chart_suggestions' => ['sometimes', 'boolean'],
            'rate_limit_per_minute' => ['sometimes', 'integer', 'min:1', 'max:120'],
            'daily_cost_cap_usd' => ['sometimes', 'numeric', 'min:0'],
            'headline_mode' => ['sometimes', 'string', 'in:deterministic,generated'],
            'project_intelligence' => ['sometimes', 'array'],
            'project_intelligence.enabled' => ['sometimes', 'boolean'],
            'project_intelligence.commit_lookback' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'project_intelligence.include_deployment_history' => ['sometimes', 'boolean'],
            'project_intelligence.show_commit_urls' => ['sometimes', 'boolean'],
        ]);

        $settings = $this->insightsSettings->save($data, $request->user()?->id);

        return response()->json(['insights' => $settings]);
    }

    public function saveRecipients(Request $request): JsonResponse
    {
        $data = $request->validate([
            'recipients' => ['present', 'array'],
            'recipients.*.user_id' => ['required', 'integer', 'exists:users,id'],
            'recipients.*.name' => ['nullable', 'string', 'max:120'],
            'recipients.*.phone' => ['nullable', 'string', 'max:32'],
            'recipients.*.audience' => ['required', 'in:ceo,sales'],
            'recipients.*.scope_platform_ids' => ['nullable', 'array'],
            'recipients.*.scope_platform_ids.*' => ['integer'],
            'recipients.*.opt_out' => ['sometimes', 'boolean'],
        ]);

        $recipients = $this->briefingSettings->saveRecipients($data['recipients'], $request->user()?->id);

        return response()->json(['recipients' => $recipients]);
    }

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'audience' => ['required', 'in:ceo,sales'],
        ]);

        $result = $this->briefings->run($data['audience'], true, null, $request->user()?->id);

        return response()->json($result);
    }

    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'audience' => ['required', 'in:ceo,sales'],
            'send_at' => ['nullable', 'string', 'max:32'],
            'confirm_live' => ['accepted'],
        ]);

        if (! $this->briefingSettings->enabled()) {
            return response()->json([
                'message' => 'Enable weekly AI briefings before sending a live SMS test.',
            ], 422);
        }

        $timezone = $this->briefingSettings->timezone();
        $sendAt = null;
        if (! empty($data['send_at'])) {
            try {
                $sendAt = Carbon::parse((string) $data['send_at'], $timezone);
            } catch (\Throwable) {
                return response()->json(['message' => 'Choose a valid scheduled send time.'], 422);
            }
        }

        $now = Carbon::now($timezone);
        if ($sendAt && $sendAt->greaterThan($now->copy()->addMinute())) {
            RunAiBriefingJob::dispatch(
                (string) $data['audience'],
                $request->user()?->id,
            )->delay($sendAt->copy()->utc());

            return response()->json([
                'status' => 'scheduled',
                'audience' => $data['audience'],
                'scheduled_for' => $sendAt->toIso8601String(),
                'timezone' => $timezone,
            ], 202);
        }

        $result = $this->briefings->run((string) $data['audience'], false, null, $request->user()?->id);

        return response()->json($result);
    }

    public function history(Request $request): JsonResponse
    {
        $interactions = AiInteraction::query()
            ->whereIn('feature', ['briefing_ceo', 'briefing_sales'])
            ->latest('id')
            ->limit(50)
            ->get([
                'id', 'feature', 'user_id', 'provider', 'status', 'error_message',
                'latency_ms', 'input_tokens', 'output_tokens', 'est_cost_usd', 'created_at',
            ]);

        return response()->json([
            'runs' => $this->recentRuns(),
            'interactions' => $interactions,
        ]);
    }

    public function scorecards(Request $request): JsonResponse
    {
        return response()->json($this->scorecardArchivePayload($request));
    }

    public function generateScorecard(Request $request): JsonResponse
    {
        $data = $request->validate([
            'week_start' => ['required', 'date'],
        ]);

        $timezone = $this->briefingSettings->timezone();
        $weekStart = Carbon::parse((string) $data['week_start'], $timezone)->startOfWeek(Carbon::MONDAY);
        $periodStartUtc = $weekStart->copy()->utc();
        $existing = $this->findCeoScorecardForPeriod($periodStartUtc);

        if (! $existing) {
            $generationDate = $weekStart->copy()->addWeek();
            $result = $this->briefings->generateScorecard('ceo', $generationDate, $request->user()?->id);

            if (($result['status'] ?? null) !== 'completed') {
                return response()->json([
                    'message' => match ($result['reason'] ?? null) {
                        'no_recipients' => 'Add a CEO briefing recipient before generating a scorecard.',
                        default => 'Could not generate the scorecard right now.',
                    },
                    'status' => $result['status'] ?? 'failed',
                    'reason' => $result['reason'] ?? null,
                ], 422);
            }
        }

        return response()->json($this->scorecardArchivePayload($request));
    }

    private function recentRuns(): array
    {
        return BriefingRun::query()
            ->withCount('briefings')
            ->latest('id')
            ->limit(20)
            ->get()
            ->toArray();
    }

    private function scorecardArchivePayload(Request $request): array
    {
        $timezone = $this->briefingSettings->timezone();
        $currentWeekStart = Carbon::now($timezone)->startOfWeek(Carbon::MONDAY)->subWeek();
        $weeks = [];

        for ($i = 0; $i < 8; $i++) {
            $weekStart = $currentWeekStart->copy()->subWeeks($i);
            $periodStartUtc = $weekStart->copy()->utc();
            $briefing = $this->findCeoScorecardForPeriod($periodStartUtc);
            $weeks[] = $this->serializeScorecardWeek($request, $weekStart, $briefing, $i === 0);
        }

        return [
            'timezone' => $timezone,
            'weeks' => $weeks,
            'latest' => $weeks[0] ?? null,
        ];
    }

    private function findCeoScorecardForPeriod(Carbon $periodStartUtc): ?Briefing
    {
        return Briefing::query()
            ->with(['recipients' => fn ($query) => $query->latest('id')])
            ->where('audience', 'ceo')
            ->where('period', 'weekly')
            ->whereDate('period_start', $periodStartUtc->toDateString())
            ->where('scope_hash', Briefing::scopeHashFor(null))
            ->latest('id')
            ->first();
    }

    private function serializeScorecardWeek(Request $request, Carbon $weekStart, ?Briefing $briefing, bool $isLatest): array
    {
        $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);
        $recipient = $this->scorecardRecipientForUser($request, $briefing);
        $body = $briefing?->decodedBody() ?? [];
        $metrics = collect((array) ($body['scorecards'] ?? []))->keyBy('key');

        return [
            'week_label' => 'Week '.$weekStart->isoWeek(),
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekEnd->toDateString(),
            'display' => $weekStart->format('j M').' - '.$weekEnd->format('j M Y'),
            'is_latest' => $isLatest,
            'exists' => $briefing !== null,
            'briefing_id' => $briefing?->id,
            'generated_at' => optional($briefing?->created_at)->toIso8601String(),
            'headline' => data_get($body, 'headline'),
            'summary_sms' => $briefing?->summary_sms,
            'share_url' => $recipient?->share_token ? '/b/'.$recipient->share_token : null,
            'recipient_status' => $recipient?->delivery_status,
            'metrics' => [
                'revenue' => $this->scorecardMetricSummary($metrics->get('revenue')),
                'recovery' => $this->scorecardMetricSummary($metrics->get('payment_recovery_rate')),
                'churn' => $this->scorecardMetricSummary($metrics->get('churned_profiles')),
            ],
        ];
    }

    private function scorecardRecipientForUser(Request $request, ?Briefing $briefing): ?BriefingRecipient
    {
        if (! $briefing) {
            return null;
        }

        $userId = (int) ($request->user()?->id ?? 0);
        $currentUserRecipient = $briefing->recipients->first(
            fn (BriefingRecipient $recipient) => (int) $recipient->user_id === $userId
        );

        return $currentUserRecipient ?: $briefing->recipients->first();
    }

    private function scorecardMetricSummary(mixed $metric): ?array
    {
        if (! is_array($metric) || $metric === []) {
            return null;
        }

        return [
            'label' => $metric['label'] ?? null,
            'current' => $metric['current'] ?? null,
            'prior' => $metric['prior'] ?? null,
            'delta_percent' => $metric['delta_percent'] ?? null,
            'unit' => $metric['unit'] ?? null,
            'currency' => $metric['currency'] ?? null,
            'status' => $metric['status'] ?? null,
        ];
    }

    private function eligibleUsers(): array
    {
        return User::query()
            ->whereIn('role', ['admin', 'sub_admin', 'sales', 'field_sales'])
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'))
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'is_ceo', 'assigned_market_ids', 'phone'])
            ->toArray();
    }
}
