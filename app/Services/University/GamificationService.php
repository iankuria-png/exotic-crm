<?php

namespace App\Services\University;

use App\Models\University\Badge;
use App\Models\University\Certificate;
use App\Models\University\Course;
use App\Models\University\Lesson;
use App\Models\University\LessonProgress;
use App\Models\University\Streak;
use App\Models\University\UserBadge;
use Carbon\CarbonImmutable;

class GamificationService
{
    public function onLessonCompleted(int $userId, Lesson $lesson): void
    {
        $this->evaluateBadges($userId);
        $this->touchStreak($userId);
    }

    public function onCertificateIssued(int $userId, Certificate $certificate): void
    {
        $this->evaluateBadges($userId);
    }

    public function onDailyDrillAnswered(int $userId, bool $correct): void
    {
        $this->touchStreak($userId, $correct);
        $this->evaluateBadges($userId);
    }

    public function touchStreak(int $userId, bool $countToday = true): Streak
    {
        $today = CarbonImmutable::today();
        $streak = Streak::firstOrNew(['user_id' => $userId]);
        $last = $streak->last_active_on ? CarbonImmutable::parse($streak->last_active_on)->startOfDay() : null;

        if (! $countToday) {
            return $streak->exists ? $streak : tap($streak)->save();
        }

        if (! $last) {
            $streak->current_streak = 1;
        } elseif ($last->isSameDay($today)) {
            // already counted today
        } elseif ($last->addDay()->isSameDay($today)) {
            $streak->current_streak = (int) $streak->current_streak + 1;
        } else {
            $streak->current_streak = 1;
        }

        $streak->longest_streak = max((int) $streak->longest_streak, (int) $streak->current_streak);
        $streak->last_active_on = $today;
        $streak->save();

        return $streak;
    }

    public function evaluateBadges(int $userId): array
    {
        $awarded = [];
        $alreadyEarned = UserBadge::query()->where('user_id', $userId)->pluck('badge_id')->all();
        $candidates = Badge::query()->whereNotIn('id', $alreadyEarned)->get();

        foreach ($candidates as $badge) {
            if ($this->matchesCriteria($userId, $badge)) {
                UserBadge::query()->firstOrCreate(
                    ['user_id' => $userId, 'badge_id' => $badge->id],
                    ['earned_at' => now()]
                );
                $awarded[] = $badge->code;
            }
        }

        return $awarded;
    }

    private function matchesCriteria(int $userId, Badge $badge): bool
    {
        return match ($badge->criteria_kind) {
            'lessons_completed' => $this->lessonsCompletedCount($userId) >= (int) ($badge->criteria_config['count'] ?? 1),
            'course_completed' => $this->isCourseCompleted($userId, $badge->criteria_config['course_slug'] ?? null),
            'certification_earned' => $this->hasCertification($userId, $badge->criteria_config['certification_slug'] ?? null),
            'streak_days' => $this->currentStreak($userId) >= (int) ($badge->criteria_config['days'] ?? 1),
            default => false,
        };
    }

    private function lessonsCompletedCount(int $userId): int
    {
        return LessonProgress::query()->where('user_id', $userId)->whereNotNull('completed_at')->count();
    }

    private function isCourseCompleted(int $userId, ?string $slug): bool
    {
        if (! $slug) {
            return false;
        }
        $course = Course::query()->where('slug', $slug)->with('modules.lessons')->first();
        if (! $course) {
            return false;
        }
        $lessonIds = $course->modules->flatMap(fn ($m) => $m->lessons->where('status', 'published')->pluck('id'))->all();
        if (! $lessonIds) {
            return false;
        }
        $completed = LessonProgress::query()
            ->where('user_id', $userId)
            ->whereIn('lesson_id', $lessonIds)
            ->whereNotNull('completed_at')
            ->count();

        return $completed >= count($lessonIds);
    }

    private function hasCertification(int $userId, ?string $slug): bool
    {
        if (! $slug) {
            return false;
        }
        return Certificate::query()
            ->whereHas('certification', fn ($q) => $q->where('slug', $slug))
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();
    }

    private function currentStreak(int $userId): int
    {
        return (int) optional(Streak::query()->where('user_id', $userId)->first())->current_streak;
    }
}
