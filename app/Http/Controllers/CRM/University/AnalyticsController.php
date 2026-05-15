<?php

namespace App\Http\Controllers\CRM\University;

use App\Http\Controllers\Controller;
use App\Models\University\Attempt;
use App\Models\University\Certificate;
use App\Models\University\Certification;
use App\Models\University\DrillCompletion;
use App\Models\University\LessonProgress;
use App\Models\University\Lesson;
use App\Models\University\Module;
use App\Models\University\Course;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    use SerializesUniversityPayloads;

    public function __construct(private readonly AuditService $auditService)
    {
    }

    public function team()
    {
        $users = User::query()
            ->whereIn('role', ['admin', 'sub_admin', 'sales', 'marketing'])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role']);

        $rows = $users->map(function (User $user) {
            $best = Attempt::query()
                ->where('user_id', $user->id)
                ->whereNotNull('submitted_at')
                ->orderByDesc('score_pct')
                ->first();
            $certificate = Certificate::query()
                ->with('certification')
                ->where('user_id', $user->id)
                ->whereNull('revoked_at')
                ->orderByDesc('expires_at')
                ->first();
            $status = 'Never';
            if ($certificate) {
                $status = $certificate->expires_at?->isPast() ? 'Expired' : ($certificate->expires_at?->lte(now()->addDays(30)) ? 'Expiring' : 'Active');
            }

            return [
                'user_id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'last_attempt_at' => optional($best?->submitted_at)->toIso8601String(),
                'best_score_pct' => $best?->score_pct,
                'cert_status' => $status,
                'validity_end' => optional($certificate?->expires_at)->toIso8601String(),
            ];
        });

        return response()->json(['agents' => $rows->values()]);
    }

    public function agent(Request $request, User $user)
    {
        $attempts = Attempt::query()
            ->with(['certification.course', 'answers.question.options', 'answers.selectedOption', 'certificate'])
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get();

        $this->auditService->fromSystemRequest($request, 'university_agent_quiz_viewed', 'university_agent', (int) $user->id, null, ['attempt_count' => $attempts->count()], 'Viewed university agent quiz answers');

        return response()->json([
            'agent' => $user->only(['id', 'name', 'email', 'role']),
            'attempts' => $attempts->map(fn (Attempt $attempt) => $this->serializeAttempt($attempt, true))->values(),
        ]);
    }

    public function certification(Certification $certification)
    {
        $attempts = $certification->attempts()->whereNotNull('submitted_at')->get();
        $topicTotals = [];
        foreach ($attempts as $attempt) {
            foreach (($attempt->per_topic_breakdown ?: []) as $topic => $row) {
                $topicTotals[$topic] ??= ['score_sum' => 0, 'count' => 0];
                $topicTotals[$topic]['score_sum'] += (float) ($row['score_pct'] ?? 0);
                $topicTotals[$topic]['count']++;
            }
        }

        return response()->json([
            'certification' => $this->serializeCertification($certification->load('questions.options')),
            'summary' => [
                'attempt_count' => $attempts->count(),
                'pass_rate_pct' => $attempts->count() > 0 ? round(($attempts->where('passed', true)->count() / $attempts->count()) * 100, 2) : 0,
                'average_score_pct' => round((float) $attempts->avg('score_pct'), 2),
            ],
            'topic_strength' => collect($topicTotals)->map(fn ($row, $topic) => [
                'topic' => $topic,
                'average_score_pct' => $row['count'] > 0 ? round($row['score_sum'] / $row['count'], 2) : 0,
            ])->values(),
            'hardest_questions' => DB::table('university_attempt_answers')
                ->join('university_questions', 'university_attempt_answers.question_id', '=', 'university_questions.id')
                ->select('university_questions.id', 'university_questions.prompt', DB::raw('AVG(CASE WHEN university_attempt_answers.is_correct THEN 1 ELSE 0 END) as correct_rate'))
                ->where('university_questions.certification_id', $certification->id)
                ->groupBy('university_questions.id', 'university_questions.prompt')
                ->orderBy('correct_rate')
                ->limit(10)
                ->get(),
        ]);
    }

    public function expiring()
    {
        $certificates = Certificate::query()
            ->with(['user:id,name,email,role', 'certification:id,title'])
            ->whereNull('revoked_at')
            ->whereBetween('expires_at', [now(), now()->addDays(30)])
            ->orderBy('expires_at')
            ->get();

        return response()->json([
            'certificates' => $certificates->map(fn (Certificate $certificate) => [
                'certificate' => $this->serializeCertificate($certificate),
                'user' => $certificate->user,
                'certification' => $certificate->certification,
            ])->values(),
        ]);
    }

    public function managementDashboard()
    {
        $eligibleRoles = ['admin', 'sub_admin', 'sales', 'marketing'];
        $totalStaff = User::query()->whereIn('role', $eligibleRoles)->count();

        $publishedLessonIds = Lesson::query()
            ->where('status', 'published')
            ->pluck('id');
        $totalLessons = $publishedLessonIds->count();

        $completedPerUser = DB::table('university_lesson_progress')
            ->whereNotNull('completed_at')
            ->whereIn('lesson_id', $publishedLessonIds)
            ->select('user_id', DB::raw('COUNT(*) as completed'))
            ->groupBy('user_id')
            ->pluck('completed', 'user_id');

        $teamCompletionPct = $totalStaff > 0 && $totalLessons > 0
            ? round(($completedPerUser->sum() / ($totalStaff * $totalLessons)) * 100, 1)
            : 0;

        $certifiedUserIds = Certificate::query()
            ->whereNull('revoked_at')
            ->where(function ($q) { $q->whereNull('expires_at')->orWhere('expires_at', '>', now()); })
            ->pluck('user_id')
            ->unique();
        $expiredCertUserIds = Certificate::query()
            ->whereNull('revoked_at')
            ->where('expires_at', '<=', now())
            ->pluck('user_id')
            ->unique();

        // Weakest topics across all submitted attempts
        $attempts = Attempt::query()->whereNotNull('submitted_at')->get();
        $topicAcc = [];
        foreach ($attempts as $attempt) {
            foreach (($attempt->per_topic_breakdown ?: []) as $topic => $row) {
                $topicAcc[$topic] ??= ['sum' => 0, 'count' => 0];
                $topicAcc[$topic]['sum'] += (float) ($row['score_pct'] ?? 0);
                $topicAcc[$topic]['count']++;
            }
        }
        $weakestTopics = collect($topicAcc)
            ->map(fn ($v, $topic) => ['topic' => $topic, 'average_score_pct' => $v['count'] > 0 ? round($v['sum'] / $v['count'], 1) : 0, 'attempts' => $v['count']])
            ->sortBy('average_score_pct')
            ->values()
            ->take(5);

        // Failed modules — lessons with the most thumbs-down feedback (joined with module/course)
        $failedModules = DB::table('university_lesson_feedback')
            ->join('university_lessons', 'university_lessons.id', '=', 'university_lesson_feedback.lesson_id')
            ->join('university_modules', 'university_modules.id', '=', 'university_lessons.module_id')
            ->join('university_courses', 'university_courses.id', '=', 'university_modules.course_id')
            ->select(
                'university_lessons.id as lesson_id',
                'university_lessons.title as lesson_title',
                'university_courses.title as course_title',
                DB::raw('SUM(CASE WHEN rating < 0 THEN 1 ELSE 0 END) as down'),
                DB::raw('SUM(CASE WHEN rating > 0 THEN 1 ELSE 0 END) as up')
            )
            ->groupBy('university_lessons.id', 'university_lessons.title', 'university_courses.title')
            ->orderByDesc('down')
            ->limit(8)
            ->get();

        // Daily drill accuracy (last 30 days)
        $drills = DrillCompletion::query()->where('completed_on', '>=', now()->subDays(30))->get();
        $drillAccuracy = $drills->count() > 0 ? round(($drills->where('correct', true)->count() / $drills->count()) * 100, 1) : 0;

        // Department readiness — group by role
        $readiness = collect($eligibleRoles)->map(function (string $role) use ($completedPerUser, $totalLessons, $certifiedUserIds) {
            $users = User::query()->where('role', $role)->get(['id', 'name']);
            $userCount = $users->count();
            if (! $userCount || ! $totalLessons) {
                return ['role' => $role, 'staff' => $userCount, 'avg_completion_pct' => 0, 'certified_pct' => 0];
            }
            $completionSum = $users->sum(fn ($u) => (int) ($completedPerUser[$u->id] ?? 0));
            $certifiedCount = $users->filter(fn ($u) => $certifiedUserIds->contains($u->id))->count();
            return [
                'role' => $role,
                'staff' => $userCount,
                'avg_completion_pct' => round(($completionSum / ($userCount * $totalLessons)) * 100, 1),
                'certified_pct' => round(($certifiedCount / $userCount) * 100, 1),
            ];
        })->values();

        // Staff who have not started
        $startedUserIds = LessonProgress::query()->distinct()->pluck('user_id')->unique();
        $notStarted = User::query()
            ->whereIn('role', $eligibleRoles)
            ->whereNotIn('id', $startedUserIds)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role']);

        // Staff with expired certs
        $expiredStaff = User::query()
            ->whereIn('id', $expiredCertUserIds)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role'])
            ->map(function ($u) {
                $latestExpired = Certificate::query()
                    ->where('user_id', $u->id)
                    ->whereNull('revoked_at')
                    ->where('expires_at', '<=', now())
                    ->orderByDesc('expires_at')
                    ->first();
                return [
                    'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role,
                    'expired_on' => optional($latestExpired?->expires_at)->toDateString(),
                ];
            });

        return response()->json([
            'totals' => [
                'staff' => $totalStaff,
                'team_completion_pct' => $teamCompletionPct,
                'certified_staff' => $certifiedUserIds->count(),
                'certified_pct' => $totalStaff > 0 ? round(($certifiedUserIds->count() / $totalStaff) * 100, 1) : 0,
                'expired_staff' => $expiredCertUserIds->count(),
                'daily_drill_accuracy_pct' => $drillAccuracy,
            ],
            'weakest_topics' => $weakestTopics,
            'failed_modules' => $failedModules,
            'department_readiness' => $readiness,
            'staff_not_started' => $notStarted,
            'staff_expired' => $expiredStaff,
        ]);
    }

    public function liveAttempts()
    {
        return response()->json([
            'attempts' => Attempt::query()
                ->with(['user:id,name,email,role', 'certification:id,title,time_limit_minutes'])
                ->whereNull('submitted_at')
                ->where('created_at', '>=', now()->subHours(4))
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (Attempt $attempt) => [
                    'id' => $attempt->id,
                    'user' => $attempt->user,
                    'certification' => $attempt->certification,
                    'started_at' => optional($attempt->started_at)->toIso8601String(),
                    'elapsed_seconds' => now()->diffInSeconds($attempt->started_at ?: $attempt->created_at),
                ])->values(),
        ]);
    }
}
