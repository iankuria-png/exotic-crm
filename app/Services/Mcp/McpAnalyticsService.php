<?php

namespace App\Services\Mcp;

use App\Models\AuditLog;
use App\Models\Briefing;
use App\Models\Client;
use App\Models\Commission;
use App\Services\CeoDashboardDataService;
use App\Services\ChurnAggregatorService;
use App\Services\CityPerformanceService;
use App\Services\ContactUnlockAnalyticsService;
use App\Services\ContactUnlockPulseService;
use App\Services\MarketAuthorizationService;
use App\Services\TeamActivityService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** Read-only MCP presenters over the services and aggregates used by CRM screens. */
class McpAnalyticsService
{
    public function __construct(
        private readonly CeoDashboardDataService $dashboard,
        private readonly TeamActivityService $team,
        private readonly ContactUnlockPulseService $visitorPulse,
        private readonly ContactUnlockAnalyticsService $visitorAnalytics,
        private readonly ChurnAggregatorService $churn,
        private readonly CityPerformanceService $cities,
        private readonly MarketAuthorizationService $markets,
        private readonly McpStaffAliasService $aliases,
        private readonly PseudonymService $pseudonyms,
    ) {}

    public function ceoDashboard(array $arguments, McpAuthorizationContext $auth): array
    {
        $request = app(McpRequestAdapter::class)->build($arguments, $auth->user);

        return [
            'source' => 'CeoDashboardDataService',
            'summary' => $this->dashboard->summary($request),
            'trend' => $this->dashboard->revenueTrend($request),
            'market_breakdown' => $this->dashboard->marketPie($request),
            'peak_hours' => $this->dashboard->peakHours($request),
            'agent_portfolio' => $this->aliases->present(['rows' => $this->dashboard->agentPerformance($request)])['rows'],
            'context' => $this->dashboard->context($request),
        ];
    }

    public function weeklyExecutiveScorecard(array $arguments): array
    {
        $week = isset($arguments['week_start']) ? Carbon::parse($arguments['week_start'])->startOfWeek() : null;
        $query = Briefing::query()->where('audience', 'ceo')->where('period', 'weekly')->where('scope_hash', Briefing::scopeHashFor(null));
        if ($week) {
            $query->whereDate('period_start', $week->utc()->toDateString());
        }
        $briefings = $query->latest('period_start')->limit($week ? 1 : 8)->get();

        return ['source' => 'BriefingService archived executive scorecards', 'weeks' => $briefings->map(fn (Briefing $briefing) => [
            'week_start' => optional($briefing->period_start)->toDateString(),
            'week_end' => optional($briefing->period_end)->toDateString(),
            'generated_at' => optional($briefing->created_at)->toIso8601String(),
            'scorecard' => $this->projectArchivedScorecard($briefing->decodedBody()),
        ])->all()];
    }

    public function teamPerformance(array $arguments, McpAuthorizationContext $auth): array
    {
        $platformId = $this->platform($arguments, $auth);
        $period = (string) ($arguments['period'] ?? TeamActivityService::PERIOD_WEEK);
        $data = in_array($auth->user->role, ['admin', 'sub_admin'], true)
            ? [
                'presence' => $this->team->getPresence($auth->user, $platformId),
                'leaderboard' => $this->team->getLeaderboard($period, $platformId, $auth->user, TeamActivityService::ROLE_FILTER_ALL, null, $arguments['reporting_currency'] ?? null),
                'goals' => $this->team->getGoals($platformId, $auth->user),
            ]
            : ['my_scorecard' => $this->team->getMyStats($auth->user, $period, $platformId, $arguments['reporting_currency'] ?? null)];

        return $this->aliases->present($data);
    }

    public function visitorDemand(array $arguments, McpAuthorizationContext $auth): array
    {
        $scope = $this->scope($auth, $this->platform($arguments, $auth));
        $range = (string) ($arguments['range'] ?? '7d');
        $currency = $arguments['reporting_currency'] ?? null;

        $overview = $this->visitorPulse->summary($scope, $range, null, $currency);
        $overview['top_profiles'] = collect($overview['top_profiles'] ?? [])
            ->map(function (array $row): array {
                $clientId = (int) ($row['client_id'] ?? 0);

                return array_filter([
                    'client_handle' => $clientId > 0 ? $this->pseudonyms->handle('client', $clientId) : null,
                    'profile_scope' => $clientId > 0 ? 'single_profile' : 'market_inactive_profiles',
                    'count' => (int) ($row['count'] ?? 0),
                    'amount_normalized' => $row['amount_normalized'] ?? null,
                    'amount_display' => $row['amount_display'] ?? null,
                    'normalized_currency' => $row['normalized_currency'] ?? null,
                    'source_breakdown' => $row['source_breakdown'] ?? [],
                ], static fn (mixed $value): bool => $value !== null);
            })
            ->values()
            ->all();

        return [
            'definition' => 'Contact-unlock demand only; it is not subscription revenue.',
            'overview' => $overview,
            'analytics' => $this->visitorAnalytics->analytics($scope, $range, null, $currency, null, null, (string) ($arguments['bucket'] ?? 'auto')),
        ];
    }

    public function commissionSummary(array $arguments, McpAuthorizationContext $auth): array
    {
        $query = Commission::query()->whereBetween('earned_at', [now()->subDays((int) ($arguments['days'] ?? 30)), now()]);
        if ($auth->user->role === 'field_sales') {
            $query->where('agent_user_id', $auth->user->id);
        }
        $scope = $this->scope($auth, $this->platform($arguments, $auth));
        if (is_array($scope)) {
            $query->whereHas('client', fn ($client) => $client->whereIn('platform_id', $scope));
        }
        $rows = $query->select('agent_user_id', 'currency', 'status', DB::raw('COUNT(*) as count'), DB::raw('SUM(amount) as amount'))->groupBy('agent_user_id', 'currency', 'status')->get();

        return $this->aliases->present(['definition' => 'Read-only earned and paid field commissions; payout references are excluded.', 'rows' => $rows->map(fn ($row) => [
            'user_id' => (int) $row->agent_user_id, 'name' => 'staff', 'currency' => $row->currency, 'status' => $row->status, 'count' => (int) $row->count, 'amount' => (float) $row->amount,
        ])->all()]);
    }

    public function clientOperations(array $arguments, McpAuthorizationContext $auth): array
    {
        $scope = $this->scope($auth, $this->platform($arguments, $auth));
        $query = Client::query()->notClosed();
        if (is_array($scope)) {
            $query->whereIn('platform_id', $scope);
        }
        $queue = (string) ($arguments['queue'] ?? 'all');
        if ($queue === 'failed_payment') {
            $query->where('needs_payment', true);
        } elseif ($queue === 'stalled_contacted') {
            $query->whereNotNull('last_contact_at')->where('last_contact_at', '<', now()->subHours(72));
        }
        $limit = min(50, max(1, (int) ($arguments['limit'] ?? 25)));
        $identified = (bool) ($arguments['identified'] ?? false);
        if ($identified && (! $auth->roleIs(['admin']) || ! $auth->allows('mcp:identified-clients') || ! ($arguments['confirm_identified'] ?? false) || trim((string) ($arguments['purpose'] ?? '')) === '')) {
            throw McpProtocolException::rpc(-32011, 'Named client rows require an administrator, the identified-client capability, explicit confirmation, and a purpose.', 'identified_client_denied', 403);
        }
        $total = (clone $query)->count();
        $clients = $query->latest('created_at')->limit($limit)->get(['id', 'name', 'platform_id', 'city', 'profile_status', 'needs_payment', 'first_contact_at', 'last_contact_at', 'created_at']);
        if ($identified) {
            AuditLog::query()->create(['actor_id' => $auth->user->id, 'action' => 'mcp_identified_client_rows', 'entity_type' => 'mcp_client_queue', 'entity_id' => 0, 'after_state' => ['tool' => 'exotic_client_operations', 'queue' => $queue, 'platform_id' => $arguments['platform_id'] ?? null, 'count' => $clients->count(), 'purpose' => trim((string) $arguments['purpose'])], 'created_at' => now()]);
        }

        return ['queue' => $queue, 'total' => $total, 'limit' => $limit, 'identified' => $identified, 'rows' => $clients->map(fn (Client $client) => array_filter([
            'client_name' => $identified ? $client->name : null,
            'client_handle' => $identified ? null : app(PseudonymService::class)->handle('client', $client->id),
            'city' => $client->city, 'profile_status' => $client->profile_status, 'needs_payment' => (bool) $client->needs_payment,
            'first_contact_at' => optional($client->first_contact_at)->toIso8601String(), 'last_contact_at' => optional($client->last_contact_at)->toIso8601String(), 'created_at' => optional($client->created_at)->toIso8601String(),
        ], fn ($value) => $value !== null))->all()];
    }

    public function lifecycle(array $arguments, McpAuthorizationContext $auth): array
    {
        $from = Carbon::parse($arguments['from'] ?? now()->subDays(29)->toDateString())->startOfDay();
        $to = Carbon::parse($arguments['to'] ?? now()->toDateString())->endOfDay();
        $scope = $this->scope($auth, $this->platform($arguments, $auth));

        return ['definition' => 'Lifecycle, retention and churn use CRM churn definitions and coverage caveats.', 'summary' => $this->churn->summary($from, $to, $scope ?? []), 'movement' => $this->churn->movement($from, $to, $scope ?? [])];
    }

    public function cityPerformance(array $arguments, McpAuthorizationContext $auth): array
    {
        $platformId = $this->platform($arguments, $auth);
        if (! $platformId) {
            throw McpProtocolException::rpc(-32602, 'platform_id is required for city performance.', 'invalid_params', 422);
        }
        $rows = Client::query()->notClosed()->where('platform_id', $platformId)->whereNotNull('city')->where('city', '!=', '')
            ->select('city', DB::raw('COUNT(*) as client_count'), DB::raw('SUM(CASE WHEN notactive = 0 THEN 1 ELSE 0 END) as active_count'), DB::raw('SUM(CASE WHEN verified = 1 THEN 1 ELSE 0 END) as verified_count'))
            ->groupBy('city')->orderByDesc('client_count')->limit(100)->get()->map(fn ($row) => ['city' => $row->city, 'client_count' => (int) $row->client_count, 'active_count' => (int) $row->active_count, 'verified_count' => (int) $row->verified_count, 'views' => null, 'contact_rate' => null])->values();
        $scored = $this->cities->score($rows->map(fn ($row) => ['client_count' => $row['client_count'], 'views' => 0, 'contact_rate' => 0])->all());
        $rows = $rows->map(function (array $row, int $index) use ($scored): array {
            $row['performance'] = $scored[$index]['performance'] ?? ['index' => null, 'band' => 'insufficient_data'];
            $row['analytics_status'] = 'unavailable';

            return $row;
        });

        return ['definition' => '30% client count / 40% views / 30% contact rate. Views/contact rate are unavailable when analytics is not supplied.', 'platform_id' => $platformId, 'cities' => $rows->all()];
    }

    private function platform(array $arguments, McpAuthorizationContext $auth): ?int
    {
        $platformId = isset($arguments['platform_id']) ? (int) $arguments['platform_id'] : null;
        if ($platformId && ! $this->markets->userCanAccessPlatform($auth->user, $platformId)) {
            throw McpProtocolException::rpc(-32001, 'The requested market is not available to this account.', 'market_denied', 403);
        }

        return $platformId ?: null;
    }

    private function scope(McpAuthorizationContext $auth, ?int $platformId): ?array
    {
        return $platformId ? [$platformId] : $auth->platformIds;
    }

    /**
     * Archived scorecards predate the MCP boundary and are persisted JSON, not a
     * connector contract. Remove raw entity identifiers and identifying labels
     * before the general sanitizer applies its final fail-closed verification.
     */
    private function projectArchivedScorecard(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $forbidden = [
            'clientid', 'clientname', 'dealid', 'paymentid', 'leadid', 'agentid', 'agentname',
            'staffid', 'staffname', 'userid', 'username', 'name', 'phone', 'email', 'bio',
            'notes', 'body', 'rawpayload', 'paymentdata', 'crmurl',
        ];
        $projected = [];
        foreach ($value as $key => $child) {
            $normalizedKey = strtolower(str_replace(['_', '-'], '', (string) $key));
            if (in_array($normalizedKey, $forbidden, true)) {
                continue;
            }
            $projected[$key] = $this->projectArchivedScorecard($child);
        }

        return $projected;
    }
}
