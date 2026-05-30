<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\AiInteraction;
use App\Models\BriefingRun;
use App\Models\Platform;
use App\Models\User;
use App\Services\Ai\AiBriefingSettingsService;
use App\Services\Ai\AiInsightsSettingsService;
use App\Services\Ai\BriefingService;
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
            'briefings'  => $this->briefingSettings->settings(),
            'insights'   => $this->insightsSettings->settings(),
            'recipients' => $this->briefingSettings->recipients(),
            'users'      => $this->eligibleUsers(),
            'platforms'  => Platform::query()->orderBy('name')->get(['id', 'name', 'country'])->toArray(),
            'recent_runs' => $this->recentRuns(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled'             => ['sometimes', 'boolean'],
            'admin_override'      => ['sometimes', 'boolean'],
            'weekly_cost_cap_usd' => ['sometimes', 'numeric', 'min:0'],
            'link_ttl_days'       => ['sometimes', 'integer', 'min:1', 'max:90'],
            'timezone'            => ['sometimes', 'string', 'max:64'],
            'base_url'            => ['sometimes', 'string', 'max:255'],
            'sms_provider_override' => ['sometimes', 'nullable', 'string', 'max:64'],
            'schedule'            => ['sometimes', 'array'],
            'schedule.ceo_enabled'   => ['sometimes', 'boolean'],
            'schedule.sales_enabled' => ['sometimes', 'boolean'],
            'schedule.ceo_time'      => ['sometimes', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'schedule.sales_time'    => ['sometimes', 'string', 'regex:/^\d{2}:\d{2}$/'],
        ]);

        $settings = $this->briefingSettings->save($data, $request->user()?->id);

        return response()->json(['briefings' => $settings]);
    }

    public function updateInsights(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled'              => ['sometimes', 'boolean'],
            'allowed_roles'        => ['sometimes', 'array'],
            'allowed_roles.*'      => ['string', 'max:32'],
            'sources'              => ['sometimes', 'array'],
            'default_row_limit'    => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'max_row_limit'        => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'sql_timeout_seconds'  => ['sometimes', 'integer', 'min:1', 'max:60'],
            'show_generated_sql'   => ['sometimes', 'boolean'],
            'chart_suggestions'    => ['sometimes', 'boolean'],
            'rate_limit_per_minute' => ['sometimes', 'integer', 'min:1', 'max:120'],
            'daily_cost_cap_usd'   => ['sometimes', 'numeric', 'min:0'],
        ]);

        $settings = $this->insightsSettings->save($data, $request->user()?->id);

        return response()->json(['insights' => $settings]);
    }

    public function saveRecipients(Request $request): JsonResponse
    {
        $data = $request->validate([
            'recipients'                       => ['present', 'array'],
            'recipients.*.user_id'             => ['required', 'integer', 'exists:users,id'],
            'recipients.*.name'                => ['nullable', 'string', 'max:120'],
            'recipients.*.phone'               => ['nullable', 'string', 'max:32'],
            'recipients.*.audience'            => ['required', 'in:ceo,sales'],
            'recipients.*.scope_platform_ids'  => ['nullable', 'array'],
            'recipients.*.scope_platform_ids.*' => ['integer'],
            'recipients.*.opt_out'             => ['sometimes', 'boolean'],
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
            'runs'         => $this->recentRuns(),
            'interactions' => $interactions,
        ]);
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
