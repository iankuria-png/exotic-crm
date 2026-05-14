<?php

namespace App\Http\Controllers\CRM\University;

use App\Http\Controllers\Controller;
use App\Models\University\Attempt;
use App\Models\University\Certificate;
use App\Models\University\Certification;
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
