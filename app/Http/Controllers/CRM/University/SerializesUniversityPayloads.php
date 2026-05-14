<?php

namespace App\Http\Controllers\CRM\University;

use App\Models\University\Attempt;
use App\Models\University\Certificate;
use App\Models\University\Certification;
use App\Models\University\Course;
use App\Models\University\Lesson;
use App\Models\University\LessonMedia;
use App\Models\University\LessonProgress;
use App\Models\University\Question;

trait SerializesUniversityPayloads
{
    private function isUniversityAdmin($request): bool
    {
        return in_array(optional($request->user())->role, ['admin', 'sub_admin'], true);
    }

    private function serializeCourse(Course $course, ?int $userId = null, bool $includeDrafts = false): array
    {
        $lessons = $course->relationLoaded('modules')
            ? $course->modules->flatMap(fn ($module) => $module->relationLoaded('lessons') ? $module->lessons : collect())
            : collect();
        $lessonIds = $lessons->pluck('id')->all();
        $progressByLesson = $userId && $lessonIds !== []
            ? LessonProgress::query()->where('user_id', $userId)->whereIn('lesson_id', $lessonIds)->get()->keyBy('lesson_id')
            : collect();
        $completed = $progressByLesson->filter(fn (LessonProgress $progress) => $progress->completed_at)->count();
        $totalLessons = $lessons->filter(fn (Lesson $lesson) => $includeDrafts || $lesson->status === 'published')->count();

        return [
            'id' => $course->id,
            'slug' => $course->slug,
            'title' => $course->title,
            'summary' => $course->summary,
            'cover_image_path' => $course->cover_image_path,
            'cover_image_url' => $course->cover_image_url,
            'status' => $course->status,
            'visibility' => $course->visibility,
            'required_for_roles' => $course->required_for_roles ?: [],
            'prerequisite_course_id' => $course->prerequisite_course_id,
            'order' => (int) $course->order,
            'published_at' => optional($course->published_at)->toIso8601String(),
            'progress_pct' => $totalLessons > 0 ? round(($completed / $totalLessons) * 100) : 0,
            'completed_lessons' => $completed,
            'lesson_count' => $totalLessons,
            'duration_minutes' => (int) $lessons->sum('duration_minutes'),
            'certifications' => $course->relationLoaded('certifications')
                ? $course->certifications->map(fn (Certification $certification) => $this->serializeCertification($certification, $userId))->values()
                : [],
            'modules' => $course->relationLoaded('modules')
                ? $course->modules->map(fn ($module) => [
                    'id' => $module->id,
                    'course_id' => $module->course_id,
                    'slug' => $module->slug,
                    'title' => $module->title,
                    'summary' => $module->summary,
                    'order' => (int) $module->order,
                    'lessons' => $module->relationLoaded('lessons')
                        ? $module->lessons
                            ->filter(fn (Lesson $lesson) => $includeDrafts || $lesson->status === 'published')
                            ->map(fn (Lesson $lesson) => $this->serializeLesson($lesson, $progressByLesson->get($lesson->id), $includeDrafts))
                            ->values()
                        : [],
                ])->values()
                : [],
        ];
    }

    private function serializeLesson(Lesson $lesson, ?LessonProgress $progress = null, bool $includeDrafts = false): array
    {
        return [
            'id' => $lesson->id,
            'module_id' => $lesson->module_id,
            'slug' => $lesson->slug,
            'title' => $lesson->title,
            'body' => $lesson->body,
            'body_draft' => $includeDrafts ? $lesson->body_draft : null,
            'duration_minutes' => (int) $lesson->duration_minutes,
            'order' => (int) $lesson->order,
            'status' => $lesson->status,
            'progress' => $progress ? [
                'viewed_at' => optional($progress->viewed_at)->toIso8601String(),
                'completed_at' => optional($progress->completed_at)->toIso8601String(),
                'seconds_spent' => (int) $progress->seconds_spent,
                'scroll_y' => (int) $progress->scroll_y,
            ] : null,
            'media' => $lesson->relationLoaded('media')
                ? $lesson->media->map(fn (LessonMedia $media) => [
                    'id' => $media->id,
                    'kind' => $media->kind,
                    'disk_path' => $includeDrafts ? $media->disk_path : null,
                    'embed_url' => $media->embed_url,
                    'mime' => $media->mime,
                    'size_bytes' => (int) $media->size_bytes,
                    'caption' => $media->caption,
                    'order' => (int) $media->order,
                    'url' => $media->url,
                ])->values()
                : [],
        ];
    }

    private function serializeCertification(Certification $certification, ?int $userId = null): array
    {
        $bestAttempt = $userId
            ? $certification->attempts()->where('user_id', $userId)->whereNotNull('submitted_at')->orderByDesc('score_pct')->first()
            : null;
        $activeCertificate = $userId
            ? $certification->certificates()
                ->where('user_id', $userId)
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->orderByDesc('issued_at')
                ->first()
            : null;

        return [
            'id' => $certification->id,
            'course_id' => $certification->course_id,
            'title' => $certification->title,
            'slug' => $certification->slug,
            'description' => $certification->description,
            'pass_threshold' => (int) $certification->pass_threshold,
            'time_limit_minutes' => (int) $certification->time_limit_minutes,
            'question_count' => (int) $certification->question_count,
            'max_attempts_per_window' => (int) $certification->max_attempts_per_window,
            'attempt_window_days' => (int) $certification->attempt_window_days,
            'validity_months' => (int) $certification->validity_months,
            'randomize_questions' => (bool) $certification->randomize_questions,
            'randomize_options' => (bool) $certification->randomize_options,
            'show_explanations_on_fail' => (bool) $certification->show_explanations_on_fail,
            'allow_review_before_submit' => (bool) $certification->allow_review_before_submit,
            'cert_template_id' => $certification->cert_template_id,
            'status' => $certification->status,
            'question_bank_count' => $certification->relationLoaded('questions') ? $certification->questions->count() : null,
            'best_score_pct' => $bestAttempt?->score_pct,
            'certificate' => $activeCertificate ? $this->serializeCertificate($activeCertificate) : null,
        ];
    }

    private function serializeAttempt(Attempt $attempt, bool $includeAnswers = false): array
    {
        return [
            'id' => $attempt->id,
            'certification_id' => $attempt->certification_id,
            'started_at' => optional($attempt->started_at)->toIso8601String(),
            'submitted_at' => optional($attempt->submitted_at)->toIso8601String(),
            'score_pct' => $attempt->score_pct,
            'passed' => (bool) $attempt->passed,
            'per_topic_breakdown' => $attempt->per_topic_breakdown ?: [],
            'time_spent_seconds' => (int) $attempt->time_spent_seconds,
            'certification' => $attempt->relationLoaded('certification') && $attempt->certification ? $this->serializeCertification($attempt->certification, $attempt->user_id) : null,
            'certificate' => $attempt->relationLoaded('certificate') && $attempt->certificate ? $this->serializeCertificate($attempt->certificate) : null,
            'answers' => $includeAnswers && $attempt->relationLoaded('answers')
                ? $attempt->answers->map(fn ($answer) => [
                    'question_id' => $answer->question_id,
                    'selected_option_id' => $answer->selected_option_id,
                    'is_correct' => (bool) $answer->is_correct,
                    'question' => $answer->relationLoaded('question') && $answer->question
                        ? $this->serializeQuestion($answer->question, includeCorrect: true)
                        : null,
                ])->values()
                : [],
        ];
    }

    private function serializeQuestion(Question $question, bool $includeCorrect = false): array
    {
        return [
            'id' => $question->id,
            'certification_id' => $question->certification_id,
            'kind' => $question->kind,
            'prompt' => $question->prompt,
            'scenario_context' => $question->scenario_context,
            'explanation' => $includeCorrect ? $question->explanation : null,
            'topic_tag' => $question->topic_tag,
            'weight' => (int) $question->weight,
            'order' => (int) $question->order,
            'options' => $question->relationLoaded('options')
                ? $question->options->map(fn ($option) => [
                    'id' => $option->id,
                    'question_id' => $option->question_id,
                    'text' => $option->text,
                    'is_correct' => $includeCorrect ? (bool) $option->is_correct : null,
                    'order' => (int) $option->order,
                ])->values()
                : [],
        ];
    }

    private function serializeCertificate(Certificate $certificate): array
    {
        return [
            'id' => $certificate->id,
            'certificate_code' => $certificate->certificate_code,
            'issued_at' => optional($certificate->issued_at)->toIso8601String(),
            'expires_at' => optional($certificate->expires_at)->toIso8601String(),
            'revoked_at' => optional($certificate->revoked_at)->toIso8601String(),
            'pdf_url' => $certificate->pdf_url,
            'verify_url' => url('/university/verify/' . $certificate->certificate_code),
            'status' => $certificate->revoked_at ? 'revoked' : ($certificate->expires_at && $certificate->expires_at->isPast() ? 'expired' : 'active'),
        ];
    }
}
