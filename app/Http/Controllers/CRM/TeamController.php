<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\AgentGoal;
use App\Models\User;
use App\Services\TeamActivityService;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    public function __construct(
        private readonly TeamActivityService $teamActivityService
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
        return response()->json(
            $this->teamActivityService->getPresence($request->user())
        );
    }

    public function leaderboard(Request $request)
    {
        $validated = $request->validate([
            'period' => 'nullable|in:today,week,month',
            'platform_id' => 'nullable|integer|exists:platforms,id',
        ]);

        return response()->json(
            $this->teamActivityService->getLeaderboard(
                (string) ($validated['period'] ?? TeamActivityService::PERIOD_WEEK),
                isset($validated['platform_id']) ? (int) $validated['platform_id'] : null,
                $request->user()
            )
        );
    }

    public function agentStats(Request $request, User $user)
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'platform_id' => 'nullable|integer|exists:platforms,id',
        ]);

        return response()->json(
            $this->teamActivityService->getAgentStats(
                $user,
                now()->parse((string) $validated['from']),
                now()->parse((string) $validated['to']),
                isset($validated['platform_id']) ? (int) $validated['platform_id'] : null,
                $request->user()
            )
        );
    }

    public function activityFeed(Request $request, User $user)
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'platform_id' => 'nullable|integer|exists:platforms,id',
        ]);

        return response()->json(
            $this->teamActivityService->getAgentActivityFeed(
                $user,
                now()->parse((string) $validated['date']),
                isset($validated['platform_id']) ? (int) $validated['platform_id'] : null,
                $request->user()
            )
        );
    }

    public function myStats(Request $request)
    {
        $validated = $request->validate([
            'period' => 'nullable|in:today,week,month',
            'platform_id' => 'nullable|integer|exists:platforms,id',
        ]);

        return response()->json(
            $this->teamActivityService->getMyStats(
                $request->user(),
                (string) ($validated['period'] ?? TeamActivityService::PERIOD_WEEK),
                isset($validated['platform_id']) ? (int) $validated['platform_id'] : null
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
            'period' => 'required|in:weekly,monthly',
            'platform_id' => 'nullable|integer|exists:platforms,id',
        ]);

        $goal = $this->teamActivityService->setGoal(
            (string) $validated['metric'],
            (int) $validated['target'],
            (string) $validated['period'],
            isset($validated['platform_id']) ? (int) $validated['platform_id'] : null,
            $request->user()
        );

        return response()->json([
            'goal' => [
                'id' => (int) $goal->id,
                'metric' => $goal->metric,
                'target' => (int) $goal->target,
                'period' => $goal->period,
                'platform_id' => $goal->platform_id ? (int) $goal->platform_id : null,
            ],
        ], 201);
    }

    public function deleteGoal(Request $request, AgentGoal $goal)
    {
        $this->teamActivityService->deleteGoal($goal, $request->user());

        return response()->noContent();
    }
}
