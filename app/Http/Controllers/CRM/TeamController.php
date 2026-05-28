<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\AgentGoal;
use App\Models\AgentGoalOverride;
use App\Models\User;
use App\Services\ReportingCurrencyService;
use App\Services\TeamActivityService;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    public function __construct(
        private readonly TeamActivityService $teamActivityService,
        private readonly ReportingCurrencyService $reportingCurrencyService
    ) {
    }

    public function heartbeat(Request $request)
    {
        $validated = $request->validate([
            'session_token' => 'required|string|max:36',
        ]);

        $this->teamActivityService->recordHeartbeat(
            $request->user(),
            (string) $validated['session_token'],
            (string) $request->ip(),
            (string) $request->userAgent()
        );

        return response()->noContent();
    }

    public function presence(Request $request)
    {
        $validated = $request->validate([
            'platform_id' => 'nullable|integer|exists:platforms,id',
        ]);

        return response()->json(
            $this->teamActivityService->getPresence(
                $request->user(),
                isset($validated['platform_id']) ? (int) $validated['platform_id'] : null,
            )
        );
    }

    public function leaderboard(Request $request)
    {
        $validated = $request->validate([
            'period' => 'nullable|in:today,week,month',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'platform_id' => 'nullable|integer|exists:platforms,id',
            'role_filter' => 'nullable|in:all,admin,sub_admin,sales,marketing',
            'currency_mode' => 'nullable|in:native,flat',
            'reporting_currency' => 'nullable|string|min:3|max:8',
        ]);
        $targetCurrency = $this->reportingCurrencyService->resolveTargetCurrency($validated['reporting_currency'] ?? null);
        $currencyMode = $this->reportingCurrencyService->resolveMode(
            $validated['currency_mode'] ?? null,
            !isset($validated['platform_id'])
        );

        return response()->json(
            $this->teamActivityService->getLeaderboard(
                (string) ($validated['period'] ?? TeamActivityService::PERIOD_WEEK),
                isset($validated['platform_id']) ? (int) $validated['platform_id'] : null,
                $request->user(),
                (string) ($validated['role_filter'] ?? TeamActivityService::ROLE_FILTER_ALL),
                $currencyMode,
                $targetCurrency,
                isset($validated['from']) ? now()->parse((string) $validated['from']) : null,
                isset($validated['to']) ? now()->parse((string) $validated['to']) : null,
            )
        );
    }

    public function agentStats(Request $request, User $user)
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'platform_id' => 'nullable|integer|exists:platforms,id',
            'reporting_currency' => 'nullable|string|min:3|max:8',
        ]);
        $targetCurrency = $this->reportingCurrencyService->resolveTargetCurrency($validated['reporting_currency'] ?? null);

        return response()->json(
            $this->teamActivityService->getAgentStats(
                $user,
                now()->parse((string) $validated['from']),
                now()->parse((string) $validated['to']),
                isset($validated['platform_id']) ? (int) $validated['platform_id'] : null,
                $request->user(),
                $targetCurrency
            )
        );
    }

    public function activityFeed(Request $request, User $user)
    {
        $validated = $request->validate([
            'date' => 'nullable|date',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'platform_id' => 'nullable|integer|exists:platforms,id',
            'entity_type' => 'nullable|in:client,lead,payment,deal,user,platform',
            'search' => 'nullable|string|max:120',
            'include_system' => 'nullable|boolean',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:5|max:100',
        ]);

        $hasDate = isset($validated['date']);
        $hasRange = isset($validated['from'], $validated['to']);

        if (!$hasDate && !$hasRange) {
            return response()->json([
                'message' => 'Provide either a date or a from/to range.',
                'errors' => [
                    'date' => ['Provide either a date or a from/to range.'],
                ],
            ], 422);
        }

        $from = $hasRange
            ? now()->parse((string) $validated['from'])
            : now()->parse((string) $validated['date']);
        $to = $hasRange
            ? now()->parse((string) $validated['to'])
            : now()->parse((string) $validated['date']);

        return response()->json(
            $this->teamActivityService->getAgentActivityFeed(
                $user,
                $from,
                $to,
                isset($validated['platform_id']) ? (int) $validated['platform_id'] : null,
                $request->user(),
                [
                    'entity_type' => isset($validated['entity_type']) ? (string) $validated['entity_type'] : null,
                    'search' => isset($validated['search']) ? (string) $validated['search'] : null,
                    'include_system' => (bool) ($validated['include_system'] ?? false),
                    'page' => (int) ($validated['page'] ?? 1),
                    'per_page' => (int) ($validated['per_page'] ?? 25),
                ]
            )
        );
    }

    public function myStats(Request $request)
    {
        $validated = $request->validate([
            'period' => 'nullable|in:today,week,month',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'platform_id' => 'nullable|integer|exists:platforms,id',
            'reporting_currency' => 'nullable|string|min:3|max:8',
        ]);
        $targetCurrency = $this->reportingCurrencyService->resolveTargetCurrency($validated['reporting_currency'] ?? null);

        return response()->json(
            $this->teamActivityService->getMyStats(
                $request->user(),
                (string) ($validated['period'] ?? TeamActivityService::PERIOD_WEEK),
                isset($validated['platform_id']) ? (int) $validated['platform_id'] : null,
                $targetCurrency,
                isset($validated['from']) ? now()->parse((string) $validated['from']) : null,
                isset($validated['to']) ? now()->parse((string) $validated['to']) : null,
            )
        );
    }

    public function goals(Request $request)
    {
        $validated = $request->validate([
            'period' => 'nullable|in:weekly,monthly',
            'platform_id' => 'nullable|integer|exists:platforms,id',
        ]);

        return response()->json(
            $this->teamActivityService->getGoals(
                isset($validated['platform_id']) ? (int) $validated['platform_id'] : null,
                $request->user(),
                (string) ($validated['period'] ?? TeamActivityService::GOAL_PERIOD_WEEKLY)
            )
        );
    }

    public function setGoal(Request $request)
    {
        $validated = $request->validate([
            'metric' => 'required|string|max:50',
            'target' => 'required|integer|min:1',
            'target_currency' => 'nullable|string|min:3|max:8',
            'period' => 'required|in:weekly,monthly',
            'platform_id' => 'nullable|integer|exists:platforms,id',
            'role_scope' => 'nullable|in:sales,marketing,sub_admin,all',
        ]);

        $goal = $this->teamActivityService->setGoal(
            (string) $validated['metric'],
            (int) $validated['target'],
            (string) $validated['period'],
            isset($validated['platform_id']) ? (int) $validated['platform_id'] : null,
            (string) ($validated['role_scope'] ?? 'sales'),
            isset($validated['target_currency']) ? (string) $validated['target_currency'] : null,
            $request->user()
        );

        return response()->json([
            'goal' => [
                'id' => (int) $goal->id,
                'metric' => $goal->metric,
                'target' => (int) $goal->target,
                'target_currency' => $goal->target_currency,
                'period' => $goal->period,
                'platform_id' => $goal->platform_id ? (int) $goal->platform_id : null,
                'role_scope' => $goal->role_scope,
            ],
        ], 201);
    }

    public function deleteGoal(Request $request, AgentGoal $goal)
    {
        $this->teamActivityService->deleteGoal($goal, $request->user());

        return response()->noContent();
    }

    public function setGoalOverride(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'metric' => 'required|string|max:50',
            'target' => 'required|integer|min:1',
            'target_currency' => 'nullable|string|min:3|max:8',
            'period' => 'required|in:weekly,monthly',
            'platform_id' => 'required|integer|exists:platforms,id',
        ]);

        $goalOverride = $this->teamActivityService->setGoalOverride(
            (int) $validated['user_id'],
            (string) $validated['metric'],
            (int) $validated['target'],
            (string) $validated['period'],
            (int) $validated['platform_id'],
            isset($validated['target_currency']) ? (string) $validated['target_currency'] : null,
            $request->user()
        );

        return response()->json([
            'goal_override' => [
                'id' => (int) $goalOverride->id,
                'user_id' => (int) $goalOverride->user_id,
                'metric' => $goalOverride->metric,
                'target' => (int) $goalOverride->target,
                'target_currency' => $goalOverride->target_currency,
                'period' => $goalOverride->period,
                'platform_id' => (int) $goalOverride->platform_id,
            ],
        ], 201);
    }

    public function deleteGoalOverride(Request $request, AgentGoalOverride $goalOverride)
    {
        $this->teamActivityService->deleteGoalOverride($goalOverride, $request->user());

        return response()->noContent();
    }
}
