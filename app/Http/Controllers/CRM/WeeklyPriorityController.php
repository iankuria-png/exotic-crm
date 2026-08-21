<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\BriefingRecipient;
use App\Models\WeeklyPriority;
use App\Services\WeeklyPriorityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WeeklyPriorityController extends Controller
{
    public function __construct(private readonly WeeklyPriorityService $priorities) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'status' => 'nullable|in:all,pending,completed,archived',
            'audience' => 'nullable|in:all,ceo,admin,sales',
        ]);

        $items = $this->priorities->refreshVisibleProgress(
            $this->priorities->queryForUser($request->user(), $validated)->get()
        );

        $serialized = $items->map(fn (WeeklyPriority $priority) => $this->priorities->serialize($priority))->values();

        return response()->json([
            'data' => $serialized,
            'summary' => [
                'total' => $serialized->count(),
                'pending' => $serialized->where('status', 'pending')->count(),
                'completed' => $serialized->where('status', 'completed')->count(),
                'overdue' => $serialized->where('is_overdue', true)->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($this->priorities->canCreate($request->user()), 403, 'Only CEO/admin users can create weekly priorities.');

        $priority = $this->priorities->create($request->user(), $this->validatedPayload($request));

        return response()->json([
            'priority' => $this->priorities->serialize($priority),
        ], 201);
    }

    public function update(Request $request, WeeklyPriority $priority): JsonResponse
    {
        $payload = $this->validatedPayload($request, true);
        abort_unless($this->priorities->canUpdate($request->user(), $priority, $payload), 403, 'You cannot update this weekly priority.');

        $priority = $this->priorities->update($priority, $payload, $request->user());

        return response()->json([
            'priority' => $this->priorities->serialize($priority),
        ]);
    }

    public function destroy(Request $request, WeeklyPriority $priority): JsonResponse
    {
        abort_unless($this->priorities->canCreate($request->user()), 403, 'Only CEO/admin users can archive weekly priorities.');

        $priority->update(['status' => 'archived']);

        return response()->json([
            'priority' => $this->priorities->serialize($priority->fresh(['platform:id,name,country', 'owner:id,name,role', 'creator:id,name,role'])),
        ]);
    }

    public function storeFromBriefing(Request $request, string $token): JsonResponse
    {
        abort_unless($this->priorities->canCreate($request->user()), 403, 'Only CEO/admin users can create priorities from briefings.');

        $recipient = BriefingRecipient::query()
            ->where('share_token', $token)
            ->first();

        abort_unless($recipient && ! $recipient->isExpired(), 404, 'Briefing link is not available.');

        $payload = $this->validatedPayload($request);
        $payload['source_type'] = 'briefing';
        $payload['source_id'] = (int) $recipient->briefing_id;

        $priority = $this->priorities->create($request->user(), $payload);

        return response()->json([
            'priority' => $this->priorities->serialize($priority),
        ], 201);
    }

    private function validatedPayload(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes|required' : 'required';

        return $request->validate([
            'title' => "{$required}|string|max:180",
            'description' => 'sometimes|nullable|string|max:3000',
            'status' => 'sometimes|required|in:pending,completed,archived',
            'priority_level' => 'sometimes|required|in:critical,high,normal',
            'audience' => 'sometimes|required|in:all,ceo,admin,sales',
            'platform_id' => 'sometimes|nullable|integer|exists:platforms,id',
            'owner_user_id' => 'sometimes|nullable|integer|exists:users,id',
            'week_start' => 'sometimes|nullable|date',
            'week_end' => 'sometimes|nullable|date|after_or_equal:week_start',
            'due_at' => 'sometimes|nullable|date',
            'completion_mode' => 'sometimes|required|in:manual,metric,hybrid',
            'metric_key' => 'sometimes|nullable|in:revenue,average_daily_revenue,payment_recovery_rate,new_paid_customers,active_subscriber_snapshot,churned_profiles,lost_value_to_churn,team_active_hours',
            'target_operator' => 'sometimes|nullable|in:gte,lte',
            'target_value' => 'sometimes|nullable|numeric|min:0',
            'target_currency' => 'sometimes|nullable|string|max:8',
            'metadata' => 'sometimes|array',
        ]);
    }
}
