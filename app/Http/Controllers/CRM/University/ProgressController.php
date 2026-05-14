<?php

namespace App\Http\Controllers\CRM\University;

use App\Http\Controllers\Controller;
use App\Models\University\Lesson;
use App\Models\University\LessonProgress;
use App\Services\University\GamificationService;
use Illuminate\Http\Request;

class ProgressController extends Controller
{
    public function __construct(private readonly GamificationService $gamification)
    {
    }

    public function store(Request $request, Lesson $lesson)
    {
        $validated = $request->validate([
            'seconds_spent' => ['sometimes', 'integer', 'min:0'],
            'completed' => ['sometimes', 'boolean'],
            'scroll_y' => ['sometimes', 'integer', 'min:0'],
        ]);

        $existing = LessonProgress::query()
            ->where('user_id', $request->user()->id)
            ->where('lesson_id', $lesson->id)
            ->first();

        $wasIncomplete = ! optional($existing)->completed_at;

        $progress = LessonProgress::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'lesson_id' => $lesson->id],
            [
                'viewed_at' => now(),
                'seconds_spent' => max((int) ($validated['seconds_spent'] ?? 0), (int) optional($existing)->seconds_spent),
                'scroll_y' => (int) ($validated['scroll_y'] ?? 0),
                'completed_at' => $request->boolean('completed') ? ($existing?->completed_at ?: now()) : $existing?->completed_at,
            ]
        );

        $newlyEarned = [];
        if ($wasIncomplete && $progress->completed_at) {
            $this->gamification->onLessonCompleted($request->user()->id, $lesson);
            $newlyEarned = $this->gamification->evaluateBadges($request->user()->id);
        }

        return response()->json([
            'message' => 'Progress recorded.',
            'progress' => $progress,
            'newly_earned_badges' => $newlyEarned,
        ]);
    }
}
