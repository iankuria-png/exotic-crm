<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\AgentTodo;
use App\Services\MarketAuthorizationService;
use App\Services\TeamActivityService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AgentTodoController extends Controller
{
    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly TeamActivityService $teamActivityService
    ) {
    }

    public function index(Request $request)
    {
        $user = $request->user();

        $todos = AgentTodo::query()
            ->where('user_id', $user->id)
            ->with(['goal.platform:id,name'])
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $goalProgress = collect($this->teamActivityService->getGoalProgress($user))
            ->keyBy(fn (array $goal) => (int) ($goal['goal_id'] ?? 0));

        return response()->json([
            'data' => $todos->map(fn (AgentTodo $todo) => $this->serializeTodo($todo, $goalProgress))->values(),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $validated = $request->validate([
            'content' => 'required|string|max:1000',
            'goal_id' => 'nullable|integer|exists:agent_goals,id',
            'due_at' => 'nullable|date',
        ]);

        $goalId = isset($validated['goal_id']) ? (int) $validated['goal_id'] : null;
        $this->assertGoalAvailableToUser($user, $goalId);

        $nextSortOrder = (int) AgentTodo::query()
            ->where('user_id', $user->id)
            ->max('sort_order');

        $todo = AgentTodo::query()->create([
            'user_id' => (int) $user->id,
            'content' => trim((string) $validated['content']),
            'status' => 'pending',
            'goal_id' => $goalId,
            'due_at' => $validated['due_at'] ?? null,
            'sort_order' => $nextSortOrder + 1,
        ]);

        $todo->load(['goal.platform:id,name']);
        $goalProgress = collect($this->teamActivityService->getGoalProgress($user))
            ->keyBy(fn (array $goal) => (int) ($goal['goal_id'] ?? 0));

        return response()->json([
            'todo' => $this->serializeTodo($todo, $goalProgress),
        ], 201);
    }

    public function update(Request $request, AgentTodo $todo)
    {
        $user = $request->user();
        $this->assertTodoOwnership($todo, $user->id);

        $validated = $request->validate([
            'content' => 'sometimes|required|string|max:1000',
            'status' => 'sometimes|required|in:pending,done',
            'goal_id' => 'sometimes|nullable|integer|exists:agent_goals,id',
            'due_at' => 'sometimes|nullable|date',
            'sort_order' => 'sometimes|nullable|integer|min:0',
        ]);

        if (array_key_exists('goal_id', $validated)) {
            $goalId = $validated['goal_id'] !== null ? (int) $validated['goal_id'] : null;
            $this->assertGoalAvailableToUser($user, $goalId);
            $validated['goal_id'] = $goalId;
        }

        if (array_key_exists('content', $validated)) {
            $validated['content'] = trim((string) $validated['content']);
        }

        $todo->fill($validated);
        $todo->save();
        $todo->load(['goal.platform:id,name']);

        $goalProgress = collect($this->teamActivityService->getGoalProgress($user))
            ->keyBy(fn (array $goal) => (int) ($goal['goal_id'] ?? 0));

        return response()->json([
            'todo' => $this->serializeTodo($todo, $goalProgress),
        ]);
    }

    public function destroy(Request $request, AgentTodo $todo)
    {
        $this->assertTodoOwnership($todo, $request->user()->id);
        $todo->delete();

        return response()->noContent();
    }

    private function assertTodoOwnership(AgentTodo $todo, int $userId): void
    {
        if ((int) $todo->user_id !== $userId) {
            abort(403, 'You do not have permission to modify this to-do item.');
        }
    }

    private function assertGoalAvailableToUser($user, ?int $goalId): void
    {
        if (!$goalId) {
            return;
        }

        $availableGoals = collect($this->teamActivityService->getGoalProgress($user));
        $allowed = $availableGoals->contains(fn (array $goal) => (int) ($goal['goal_id'] ?? 0) === $goalId);

        if (!$allowed) {
            throw ValidationException::withMessages([
                'goal_id' => 'The selected goal is not available in your current goal set.',
            ]);
        }

        $platformId = $availableGoals
            ->first(fn (array $goal) => (int) ($goal['goal_id'] ?? 0) === $goalId)['platform_id'] ?? null;

        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $user,
            $platformId ? (int) $platformId : null,
            'You do not have access to that goal market.'
        );
    }

    private function serializeTodo(AgentTodo $todo, $goalProgress): array
    {
        $progress = $todo->goal_id ? $goalProgress->get((int) $todo->goal_id) : null;

        return [
            'id' => (int) $todo->id,
            'content' => $todo->content,
            'status' => $todo->status,
            'due_at' => optional($todo->due_at)->toDateTimeString(),
            'sort_order' => (int) $todo->sort_order,
            'created_at' => optional($todo->created_at)->toDateTimeString(),
            'updated_at' => optional($todo->updated_at)->toDateTimeString(),
            'goal' => $todo->goal ? [
                'id' => (int) $todo->goal->id,
                'metric' => $todo->goal->metric,
                'label' => $progress['label'] ?? $todo->goal->metric,
                'target' => (int) $todo->goal->target,
                'period' => $todo->goal->period,
                'platform_id' => $todo->goal->platform_id ? (int) $todo->goal->platform_id : null,
                'platform_name' => $todo->goal->platform?->name,
                'role_scope' => $todo->goal->role_scope,
                'progress' => $progress ? [
                    'current' => (int) ($progress['current'] ?? 0),
                    'target' => (int) ($progress['target'] ?? 0),
                    'percentage' => (int) ($progress['percentage'] ?? 0),
                ] : null,
            ] : null,
        ];
    }
}
