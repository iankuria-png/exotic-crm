<?php

namespace App\Services\University;

use App\Models\University\Attempt;
use App\Models\University\AttemptAnswer;
use App\Models\University\Certification;
use App\Models\University\Question;
use App\Models\University\QuestionOption;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuizService
{
    public function startAttempt(Certification $certification, User $user): Attempt
    {
        if ($certification->status !== 'published') {
            throw ValidationException::withMessages([
                'certification' => 'This certification is not available yet.',
            ]);
        }

        $windowStart = now()->subDays((int) $certification->attempt_window_days);
        $recentAttempts = Attempt::query()
            ->where('user_id', $user->id)
            ->where('certification_id', $certification->id)
            ->where('created_at', '>=', $windowStart)
            ->count();

        if ($recentAttempts >= (int) $certification->max_attempts_per_window) {
            throw ValidationException::withMessages([
                'attempts' => sprintf(
                    'Attempt limit reached. Try again after the %d day window resets.',
                    (int) $certification->attempt_window_days
                ),
            ]);
        }

        $questionQuery = $certification->questions()->whereHas('options', fn ($query) => $query->where('is_correct', true));
        $questions = $certification->randomize_questions
            ? $questionQuery->inRandomOrder()->limit((int) $certification->question_count)->get()
            : $questionQuery->limit((int) $certification->question_count)->get();

        if ($questions->isEmpty()) {
            throw ValidationException::withMessages([
                'questions' => 'This certification does not have a usable question bank yet.',
            ]);
        }

        return Attempt::create([
            'user_id' => $user->id,
            'certification_id' => $certification->id,
            'question_order' => $questions->pluck('id')->values()->all(),
            'started_at' => now(),
        ]);
    }

    public function submitAttempt(Attempt $attempt, array $answers): Attempt
    {
        if ($attempt->submitted_at) {
            throw ValidationException::withMessages([
                'attempt' => 'This attempt has already been submitted.',
            ]);
        }

        $attempt->loadMissing('certification');
        $certification = $attempt->certification;
        $timeLimitSeconds = max(1, (int) $certification->time_limit_minutes) * 60;
        $timeSpent = now()->diffInSeconds($attempt->started_at ?: $attempt->created_at);

        if ($timeSpent > ($timeLimitSeconds + 30)) {
            throw ValidationException::withMessages([
                'time_limit' => 'The time limit has elapsed for this attempt.',
            ]);
        }

        $normalizedAnswers = $this->normalizeAnswers($answers);
        $questionIds = collect($attempt->question_order ?: [])->map(fn ($id) => (int) $id)->filter()->values();
        $questions = Question::query()
            ->with('options')
            ->whereIn('id', $questionIds)
            ->get()
            ->sortBy(fn (Question $question) => $questionIds->search((int) $question->id))
            ->values();

        return DB::transaction(function () use ($attempt, $questions, $normalizedAnswers, $timeSpent, $certification) {
            AttemptAnswer::query()->where('attempt_id', $attempt->id)->delete();

            $totalWeight = 0;
            $earnedWeight = 0;
            $topics = [];

            foreach ($questions as $question) {
                $weight = max(1, (int) $question->weight);
                $totalWeight += $weight;

                $selectedOptionId = (int) ($normalizedAnswers[(int) $question->id] ?? 0);
                $selectedOption = $selectedOptionId > 0
                    ? $question->options->firstWhere('id', $selectedOptionId)
                    : null;
                $isCorrect = (bool) ($selectedOption?->is_correct);

                if ($isCorrect) {
                    $earnedWeight += $weight;
                }

                $topic = trim((string) ($question->topic_tag ?: 'General'));
                $topics[$topic] ??= ['correct' => 0, 'total' => 0, 'score_pct' => 0];
                $topics[$topic]['total'] += $weight;
                $topics[$topic]['correct'] += $isCorrect ? $weight : 0;

                AttemptAnswer::create([
                    'attempt_id' => $attempt->id,
                    'question_id' => $question->id,
                    'selected_option_id' => $selectedOption?->id,
                    'is_correct' => $isCorrect,
                ]);
            }

            foreach ($topics as $topic => $row) {
                $topics[$topic]['score_pct'] = $row['total'] > 0
                    ? round(($row['correct'] / $row['total']) * 100, 2)
                    : 0;
            }

            $score = $totalWeight > 0 ? round(($earnedWeight / $totalWeight) * 100, 2) : 0;
            $attempt->forceFill([
                'submitted_at' => now(),
                'score_pct' => $score,
                'passed' => $score >= (float) $certification->pass_threshold,
                'per_topic_breakdown' => $topics,
                'time_spent_seconds' => $timeSpent,
            ])->save();

            return $attempt->fresh(['answers.question.options', 'answers.selectedOption', 'certification.course']);
        });
    }

    public function questionsForAttempt(Attempt $attempt): Collection
    {
        $attempt->loadMissing('certification');
        $ids = collect($attempt->question_order ?: [])->map(fn ($id) => (int) $id)->filter()->values();
        $questions = Question::query()
            ->with('options')
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(fn (Question $question) => $ids->search((int) $question->id))
            ->values();

        if ($attempt->certification->randomize_options) {
            $questions->each(function (Question $question) {
                $question->setRelation('options', $question->options->shuffle()->values());
            });
        }

        return $questions;
    }

    private function normalizeAnswers(array $answers): array
    {
        $normalized = [];

        foreach ($answers as $key => $value) {
            if (is_array($value)) {
                $questionId = (int) ($value['question_id'] ?? 0);
                $optionId = (int) ($value['selected_option_id'] ?? $value['option_id'] ?? 0);
            } else {
                $questionId = (int) $key;
                $optionId = (int) $value;
            }

            if ($questionId > 0) {
                $normalized[$questionId] = $optionId;
            }
        }

        return $normalized;
    }
}
