<?php

namespace App\Http\Controllers\CRM\University;

use App\Http\Controllers\Controller;
use App\Models\University\DailyDrill;
use App\Models\University\DrillCompletion;
use App\Services\University\GamificationService;
use App\Services\University\UniversityPhase2Seeder;
use Illuminate\Http\Request;

class DailyDrillController extends Controller
{
    public function __construct(
        private readonly GamificationService $gamification,
        private readonly UniversityPhase2Seeder $phase2Seeder,
    )
    {
    }

    /**
     * Returns today's drill for the user. If they've already answered, returns the completion + drill.
     * If they haven't, picks a deterministic drill so the same user sees the same one all day.
     */
    public function today(Request $request)
    {
        $today = now()->toDateString();
        $userId = $request->user()->id;

        $completion = DrillCompletion::query()
            ->with('drill')
            ->where('user_id', $userId)
            ->where('completed_on', $today)
            ->first();

        if ($completion) {
            return response()->json([
                'completed' => true,
                'drill' => $this->serializeDrill($completion->drill, revealAnswer: true),
                'completion' => [
                    'selected_index' => $completion->selected_index,
                    'correct' => (bool) $completion->correct,
                    'completed_on' => $completion->completed_on->toDateString(),
                ],
            ]);
        }

        // Pick a deterministic drill for today × user (cycles through the pool)
        $drills = DailyDrill::query()->where('is_active', true)->orderBy('id')->get();
        if ($drills->isEmpty()) {
            $this->phase2Seeder->seedDailyDrills();
            $drills = DailyDrill::query()->where('is_active', true)->orderBy('id')->get();
        }

        if ($drills->isEmpty()) {
            return response()->json(['completed' => false, 'drill' => null]);
        }
        $index = (crc32($userId . '-' . $today)) % $drills->count();
        $drill = $drills->values()->get($index);

        return response()->json([
            'completed' => false,
            'drill' => $this->serializeDrill($drill, revealAnswer: false),
            'completion' => null,
        ]);
    }

    public function answer(Request $request, DailyDrill $drill)
    {
        $validated = $request->validate([
            'selected_index' => ['required', 'integer', 'min:0'],
        ]);

        $today = now()->toDateString();
        $userId = $request->user()->id;
        if (DrillCompletion::query()->where('user_id', $userId)->where('completed_on', $today)->exists()) {
            return response()->json(['message' => 'Daily drill already completed today.'], 409);
        }

        $correct = (int) $validated['selected_index'] === (int) $drill->correct_index;

        $completion = DrillCompletion::create([
            'user_id' => $userId,
            'drill_id' => $drill->id,
            'completed_on' => $today,
            'correct' => $correct,
            'selected_index' => (int) $validated['selected_index'],
        ]);

        $this->gamification->onDailyDrillAnswered($userId, $correct);
        $newlyEarned = $this->gamification->evaluateBadges($userId);

        return response()->json([
            'completion' => [
                'selected_index' => $completion->selected_index,
                'correct' => $correct,
                'completed_on' => $today,
            ],
            'drill' => $this->serializeDrill($drill, revealAnswer: true),
            'newly_earned_badges' => $newlyEarned,
        ]);
    }

    private function serializeDrill(DailyDrill $drill, bool $revealAnswer): array
    {
        return [
            'id' => $drill->id,
            'prompt' => $drill->prompt,
            'scenario_context' => $drill->scenario_context,
            'options' => $drill->options,
            'topic_tag' => $drill->topic_tag,
            'correct_index' => $revealAnswer ? (int) $drill->correct_index : null,
            'explanation' => $revealAnswer ? $drill->explanation : null,
        ];
    }
}
