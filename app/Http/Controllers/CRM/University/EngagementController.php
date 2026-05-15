<?php

namespace App\Http\Controllers\CRM\University;

use App\Http\Controllers\Controller;
use App\Models\University\Badge;
use App\Models\University\LessonFeedback;
use App\Models\University\Streak;
use App\Models\University\UserBadge;
use App\Models\University\LessonProgress;
use App\Models\University\Certificate;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EngagementController extends Controller
{
    use SerializesUniversityPayloads;

    public function leaderboard(Request $request)
    {
        // Score = badge points + 5 per completed lesson + 50 per active certificate
        $badgePoints = DB::table('university_user_badges')
            ->join('university_badges', 'university_badges.id', '=', 'university_user_badges.badge_id')
            ->select('university_user_badges.user_id', DB::raw('SUM(university_badges.points) as total'))
            ->groupBy('university_user_badges.user_id')
            ->pluck('total', 'user_id');

        $lessonCounts = DB::table('university_lesson_progress')
            ->whereNotNull('completed_at')
            ->select('user_id', DB::raw('COUNT(*) as total'))
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        $certCounts = DB::table('university_certificates')
            ->whereNull('revoked_at')
            ->where(function ($q) { $q->whereNull('expires_at')->orWhere('expires_at', '>', now()); })
            ->select('user_id', DB::raw('COUNT(*) as total'))
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        $streaks = Streak::query()->get()->keyBy('user_id');

        // Cast every user_id key to int up front — DB::table()->pluck() returns string keys
        // by default which silently miss matches against Eloquent's int-keyed collection.
        $badgePointsInt = collect($badgePoints)->mapWithKeys(fn ($v, $k) => [(int) $k => (int) $v]);
        $lessonCountsInt = collect($lessonCounts)->mapWithKeys(fn ($v, $k) => [(int) $k => (int) $v]);
        $certCountsInt = collect($certCounts)->mapWithKeys(fn ($v, $k) => [(int) $k => (int) $v]);
        $streaksInt = $streaks->mapWithKeys(fn ($v, $k) => [(int) $k => $v]);

        $userIds = collect()
            ->merge($badgePointsInt->keys())
            ->merge($lessonCountsInt->keys())
            ->merge($certCountsInt->keys())
            ->merge($streaksInt->keys())
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $users = User::query()->whereIn('id', $userIds)->get()->keyBy('id');

        $rows = $userIds->map(function ($userId) use ($users, $badgePointsInt, $lessonCountsInt, $certCountsInt, $streaksInt) {
            $user = $users->get($userId);
            if (! $user) return null;
            $score = (int) ($badgePointsInt[$userId] ?? 0)
                + 5 * (int) ($lessonCountsInt[$userId] ?? 0)
                + 50 * (int) ($certCountsInt[$userId] ?? 0);
            return [
                'user_id' => $userId,
                'name' => $user->name,
                'role' => $user->role,
                'lessons_completed' => (int) ($lessonCountsInt[$userId] ?? 0),
                'certificates' => (int) ($certCountsInt[$userId] ?? 0),
                'badge_points' => (int) ($badgePointsInt[$userId] ?? 0),
                'current_streak' => (int) optional($streaksInt->get($userId))->current_streak,
                'longest_streak' => (int) optional($streaksInt->get($userId))->longest_streak,
                'score' => $score,
            ];
        })
            ->filter()
            ->sortByDesc('score')
            ->values()
            ->take(50);

        return response()->json(['leaderboard' => $rows]);
    }

    public function me(Request $request)
    {
        $userId = $request->user()->id;
        $streak = Streak::query()->where('user_id', $userId)->first();
        $badges = UserBadge::query()
            ->with('badge')
            ->where('user_id', $userId)
            ->orderByDesc('earned_at')
            ->get();

        $allBadges = Badge::query()->orderBy('points')->get();

        $lessonsCompleted = LessonProgress::query()->where('user_id', $userId)->whereNotNull('completed_at')->count();
        $certificates = Certificate::query()
            ->with('certification.course')
            ->where('user_id', $userId)
            ->orderByDesc('issued_at')
            ->get();
        $activeCertificates = $certificates->filter(function ($cert) {
            if ($cert->revoked_at) return false;
            return !$cert->expires_at || $cert->expires_at->isFuture();
        })->count();

        return response()->json([
            'streak' => [
                'current' => (int) optional($streak)->current_streak,
                'longest' => (int) optional($streak)->longest_streak,
                'last_active_on' => optional(optional($streak)->last_active_on)->toDateString(),
            ],
            'stats' => [
                'lessons_completed' => $lessonsCompleted,
                'active_certificates' => $activeCertificates,
                'badges_earned' => $badges->count(),
                'badge_points' => (int) $badges->sum(fn ($b) => optional($b->badge)->points),
            ],
            'badges_earned' => $badges->map(fn ($b) => [
                'code' => $b->badge?->code,
                'title' => $b->badge?->title,
                'description' => $b->badge?->description,
                'icon' => $b->badge?->icon,
                'color' => $b->badge?->color,
                'points' => (int) optional($b->badge)->points,
                'earned_at' => optional($b->earned_at)->toIso8601String(),
            ])->values(),
            'badges_catalog' => $allBadges->map(fn ($b) => [
                'code' => $b->code,
                'title' => $b->title,
                'description' => $b->description,
                'icon' => $b->icon,
                'color' => $b->color,
                'points' => (int) $b->points,
                'earned' => $badges->contains(fn ($ub) => $ub->badge_id === $b->id),
            ])->values(),
            'certificates' => $certificates->map(fn ($c) => [
                'code' => $c->certificate_code,
                'title' => optional($c->certification)->title,
                'course' => optional(optional($c->certification)->course)->title,
                'issued_at' => optional($c->issued_at)->toIso8601String(),
                'expires_at' => optional($c->expires_at)->toIso8601String(),
                'revoked' => (bool) $c->revoked_at,
                'expired' => $c->expires_at && $c->expires_at->isPast(),
                'pdf_url' => $c->pdf_url,
            ])->values(),
        ]);
    }

    public function lessonFeedback(Request $request, \App\Models\University\Lesson $lesson)
    {
        $validated = $request->validate([
            'rating' => ['required', 'integer', 'in:-1,1'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $feedback = LessonFeedback::updateOrCreate(
            ['lesson_id' => $lesson->id, 'user_id' => $request->user()->id],
            ['rating' => $validated['rating'], 'comment' => $validated['comment'] ?? null]
        );

        return response()->json(['feedback' => $feedback]);
    }
}
