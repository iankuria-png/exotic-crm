<?php

namespace App\Services\University;

use App\Models\University\Certification;
use App\Models\University\Course;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MintlifySeedService
{
    public function seedDraftUniversity(): array
    {
        return DB::transaction(function () {
            $course = Course::firstOrCreate(
                ['slug' => 'sales-fundamentals'],
                [
                    'title' => 'Sales Fundamentals',
                    'summary' => 'Draft import of the Exotic Online sales and customer success operating playbook.',
                    'status' => 'draft',
                    'visibility' => 'all',
                    'required_for_roles' => ['sales', 'sub_admin'],
                    'order' => 1,
                ]
            );

            $modules = [
                'discover-stage-1' => ['Discover (Stage 1)', 'Qualify lead intent, market fit, and urgency.'],
                'subscription-selection-stage-5a' => ['Subscription Selection (Stage 5a)', 'Match customer goals to the right package.'],
                'failures-and-recovery-stage-5e' => ['Failures & Recovery (Stage 5e)', 'Recover stalled payments, mismatches, and objections.'],
                'renewals-stage-7' => ['Renewals (Stage 7)', 'Keep active clients renewing before expiry.'],
                'product-overview' => ['Product Overview', 'Explain what Exotic Online sells and how success is measured.'],
            ];

            $lessonCount = 0;
            foreach (array_values($modules) as $index => [$title, $summary]) {
                $module = $course->modules()->firstOrCreate(
                    ['slug' => Str::slug($title)],
                    ['title' => $title, 'summary' => $summary, 'order' => $index + 1]
                );

                $lesson = $module->lessons()->firstOrCreate(
                    ['slug' => Str::slug($title . ' guide')],
                    [
                        'title' => $title . ' Guide',
                        'body' => "# {$title}\n\n{$summary}\n\nReview the Mintlify source material, adapt examples to your market, and publish after manager approval.",
                        'body_draft' => "# {$title}\n\n{$summary}\n\nReview the Mintlify source material, adapt examples to your market, and publish after manager approval.",
                        'duration_minutes' => 12,
                        'order' => 1,
                        'status' => 'draft',
                    ]
                );
                $lessonCount += $lesson->wasRecentlyCreated ? 1 : 0;
            }

            $certification = Certification::firstOrCreate(
                ['slug' => 'core-sales-cs-certification'],
                [
                    'course_id' => $course->id,
                    'title' => 'Core Sales/CS Certification',
                    'description' => 'Scenario-grounded certification for sales and customer success agents.',
                    'pass_threshold' => 80,
                    'time_limit_minutes' => 35,
                    'question_count' => 25,
                    'max_attempts_per_window' => 3,
                    'attempt_window_days' => 30,
                    'validity_months' => 12,
                    'status' => 'draft',
                ]
            );

            $createdQuestions = $this->seedQuestions($certification);

            return [
                'course_id' => $course->id,
                'lesson_count_created' => $lessonCount,
                'certification_id' => $certification->id,
                'question_count_created' => $createdQuestions,
            ];
        });
    }

    private function seedQuestions(Certification $certification): int
    {
        if ($certification->questions()->count() >= 25) {
            return 0;
        }

        $topics = ['Discovery', 'Package Selection', 'Failure Recovery', 'Renewals', 'Product Knowledge'];
        $created = 0;

        for ($i = 1; $i <= 25; $i++) {
            $topic = $topics[($i - 1) % count($topics)];
            $question = $certification->questions()->create([
                'kind' => $i % 5 === 0 ? 'scenario' : 'mcq',
                'prompt' => "What is the best {$topic} action in scenario {$i}?",
                'scenario_context' => $i % 5 === 0 ? 'Customer says: I am interested, but I am not sure this package will work for my city.' : null,
                'explanation' => "The strongest answer connects the customer goal to a clear next action in {$topic}.",
                'topic_tag' => $topic,
                'weight' => 1,
                'order' => $i,
            ]);

            foreach (['Clarify the customer goal and recommend the next CRM action', 'Ignore the objection and ask for payment', 'Promise guaranteed results immediately', 'Move the lead to lost without follow-up'] as $index => $text) {
                $question->options()->create([
                    'text' => $text,
                    'is_correct' => $index === 0,
                    'order' => $index + 1,
                ]);
            }

            $created++;
        }

        return $created;
    }
}
