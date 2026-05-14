<?php

namespace App\Http\Controllers\CRM\University;

use App\Http\Controllers\Controller;
use App\Models\University\Attempt;
use App\Models\University\Certification;
use App\Services\AuditService;
use App\Services\University\CertificateService;
use App\Services\University\QuizService;
use Illuminate\Http\Request;

class CertificationController extends Controller
{
    use SerializesUniversityPayloads;

    public function __construct(
        private readonly QuizService $quizService,
        private readonly CertificateService $certificateService,
        private readonly AuditService $auditService
    ) {
    }

    public function index(Request $request)
    {
        $isAdmin = $this->isUniversityAdmin($request);
        $query = Certification::query()->with(['course', 'questions.options']);
        if (!$isAdmin) {
            $query->where('status', 'published');
        }

        return response()->json([
            'certifications' => $query->orderBy('title')->get()
                ->map(fn (Certification $certification) => [
                    ...$this->serializeCertification($certification, $request->user()->id),
                    'course' => $certification->course ? [
                        'id' => $certification->course->id,
                        'slug' => $certification->course->slug,
                        'title' => $certification->course->title,
                    ] : null,
                ])->values(),
        ]);
    }

    public function show(Request $request, Certification $certification)
    {
        if (!$this->isUniversityAdmin($request) && $certification->status !== 'published') {
            abort(404);
        }

        $certification->load(['course', 'questions.options']);
        $windowStart = now()->subDays((int) $certification->attempt_window_days);
        $attemptsUsed = $certification->attempts()
            ->where('user_id', $request->user()->id)
            ->where('created_at', '>=', $windowStart)
            ->count();

        return response()->json([
            'certification' => $this->serializeCertification($certification, $request->user()->id),
            'attempts_remaining' => max(0, (int) $certification->max_attempts_per_window - $attemptsUsed),
        ]);
    }

    public function startAttempt(Request $request, Certification $certification)
    {
        $attempt = $this->quizService->startAttempt($certification, $request->user());
        $questions = $this->quizService->questionsForAttempt($attempt);

        return response()->json([
            'message' => 'Attempt started.',
            'attempt' => $this->serializeAttempt($attempt->load('certification'), false),
            'questions' => $questions->map(fn ($question) => $this->serializeQuestion($question))->values(),
        ], 201);
    }

    public function submitAttempt(Request $request, Attempt $attempt)
    {
        $this->authorizeAttemptOwner($request, $attempt);
        $validated = $request->validate([
            'answers' => ['required', 'array'],
        ]);

        $submitted = $this->quizService->submitAttempt($attempt, $validated['answers']);
        $certificate = $this->certificateService->issue($submitted);

        if ($certificate) {
            $this->auditService->fromSystemRequest($request, 'university_certificate_issued', 'university_certificate', (int) $certificate->id, null, $certificate->toArray(), 'Issued university certificate');
        }

        return response()->json([
            'message' => 'Attempt submitted.',
            'attempt' => $this->serializeAttempt($submitted->load(['answers.question.options', 'answers.selectedOption', 'certification.course', 'certificate']), true),
        ]);
    }

    public function result(Request $request, Attempt $attempt)
    {
        $this->authorizeAttemptOwner($request, $attempt);

        return response()->json([
            'attempt' => $this->serializeAttempt($attempt->load(['answers.question.options', 'answers.selectedOption', 'certification.course', 'certificate']), true),
        ]);
    }

    private function authorizeAttemptOwner(Request $request, Attempt $attempt): void
    {
        if ($attempt->user_id === $request->user()->id || $this->isUniversityAdmin($request)) {
            return;
        }

        abort(403);
    }
}
