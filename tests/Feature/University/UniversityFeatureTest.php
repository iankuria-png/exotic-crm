<?php

namespace Tests\Feature\University;

use App\Models\AuditLog;
use App\Models\University\Attempt;
use App\Models\University\Certification;
use App\Models\University\Course;
use App\Models\University\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UniversityFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_author_course_and_sales_can_track_progress(): void
    {
        $admin = $this->userForRole('admin');
        $sales = $this->userForRole('sales');
        Sanctum::actingAs($admin);

        $courseResponse = $this->postJson('/api/crm/university/courses', [
            'title' => 'Advanced Sales Fundamentals',
            'summary' => 'Core training',
            'status' => 'published',
            'visibility' => 'all',
        ])->assertCreated();

        $moduleResponse = $this->postJson('/api/crm/university/courses/' . $courseResponse->json('course.id') . '/modules', [
            'title' => 'Discovery',
        ])->assertCreated();

        $lessonResponse = $this->postJson('/api/crm/university/modules/' . $moduleResponse->json('module.id') . '/lessons', [
            'title' => 'Discovery Call',
            'body_draft' => '# Discovery',
            'status' => 'published',
            'duration_minutes' => 8,
        ])->assertCreated();

        $this->getJson('/api/crm/university/courses?status=all')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Advanced Sales Fundamentals']);

        Sanctum::actingAs($sales);
        $this->postJson('/api/crm/university/lessons/' . $lessonResponse->json('lesson.id') . '/progress', [
            'completed' => true,
            'seconds_spent' => 240,
        ])->assertOk()
            ->assertJsonPath('progress.seconds_spent', 240);

        $this->getJson('/api/crm/university/courses')
            ->assertOk()
            ->assertJsonPath('courses.0.progress_pct', 100);

        $this->postJson('/api/crm/university/courses', [
            'title' => 'Forbidden Course',
        ])->assertForbidden();

        $this->assertDatabaseHas('audit_log', [
            'entity_type' => 'university_course',
            'entity_id' => $courseResponse->json('course.id'),
        ]);
    }

    public function test_quiz_attempt_scoring_attempt_limit_and_certificate_issue(): void
    {
        Storage::fake('public');

        $sales = $this->userForRole('sales');
        $certification = $this->certificationWithQuestions([
            true,
            true,
            true,
            true,
            false,
        ], [
            'pass_threshold' => 80,
            'question_count' => 5,
            'max_attempts_per_window' => 3,
            'status' => 'published',
        ]);

        Sanctum::actingAs($sales);

        $start = $this->postJson('/api/crm/university/certifications/' . $certification->id . '/attempts')
            ->assertCreated()
            ->assertJsonCount(5, 'questions');

        $answers = [];
        foreach ($start->json('questions') as $question) {
            $correct = collect(Question::query()->with('options')->findOrFail($question['id'])->options)->firstWhere('is_correct', true);
            $answers[] = [
                'question_id' => $question['id'],
                'selected_option_id' => $correct->id,
            ];
        }

        $result = $this->postJson('/api/crm/university/attempts/' . $start->json('attempt.id') . '/submit', [
            'answers' => $answers,
        ])->assertOk()
            ->assertJsonPath('attempt.passed', true);

        $this->assertGreaterThanOrEqual(80, (float) $result->json('attempt.score_pct'));
        $code = $result->json('attempt.certificate.certificate_code');
        $this->assertNotEmpty($code);
        Storage::disk('public')->assertExists('university/certificates/' . $code . '.pdf');

        $this->getJson('/api/crm/university/certificates/' . $code . '/verify')
            ->assertOk()
            ->assertJsonPath('certificate.status', 'active');

        $this->postJson('/api/crm/university/certifications/' . $certification->id . '/attempts')->assertCreated();
        $this->postJson('/api/crm/university/certifications/' . $certification->id . '/attempts')->assertCreated();
        $this->postJson('/api/crm/university/certifications/' . $certification->id . '/attempts')->assertStatus(422);
    }

    public function test_failed_attempt_does_not_issue_certificate(): void
    {
        Storage::fake('public');

        $sales = $this->userForRole('sales');
        $certification = $this->certificationWithQuestions([true, true, false], [
            'pass_threshold' => 80,
            'question_count' => 3,
            'status' => 'published',
        ]);

        Sanctum::actingAs($sales);
        $start = $this->postJson('/api/crm/university/certifications/' . $certification->id . '/attempts')->assertCreated();
        $answers = [];
        foreach ($start->json('questions') as $question) {
            $wrong = collect(Question::query()->with('options')->findOrFail($question['id'])->options)->firstWhere('is_correct', false);
            $answers[] = [
                'question_id' => $question['id'],
                'selected_option_id' => $wrong->id,
            ];
        }

        $this->postJson('/api/crm/university/attempts/' . $start->json('attempt.id') . '/submit', [
            'answers' => $answers,
        ])->assertOk()
            ->assertJsonPath('attempt.passed', false)
            ->assertJsonPath('attempt.certificate', null);

        $this->assertDatabaseCount('university_certificates', 0);
    }

    public function test_admin_agent_drilldown_is_audited(): void
    {
        $admin = $this->userForRole('admin');
        $sales = $this->userForRole('sales');
        $certification = $this->certificationWithQuestions([true], ['status' => 'published']);
        Attempt::create([
            'user_id' => $sales->id,
            'certification_id' => $certification->id,
            'started_at' => now(),
            'submitted_at' => now(),
            'score_pct' => 100,
            'passed' => true,
        ]);

        Sanctum::actingAs($admin);
        $this->getJson('/api/crm/university/analytics/agents/' . $sales->id)->assertOk();

        $this->assertTrue(AuditLog::query()
            ->where('entity_type', 'university_agent')
            ->where('entity_id', $sales->id)
            ->where('action', 'university_agent_quiz_viewed')
            ->exists());
    }

    private function certificationWithQuestions(array $correctFlags, array $overrides = []): Certification
    {
        $course = Course::create([
            'slug' => 'sales-fundamentals-' . uniqid(),
            'title' => 'Sales Fundamentals',
            'status' => 'published',
            'visibility' => 'all',
        ]);

        $certification = Certification::create(array_merge([
            'course_id' => $course->id,
            'title' => 'Core Sales/CS Certification ' . uniqid(),
            'slug' => 'core-sales-cs-' . uniqid(),
            'description' => 'Core cert',
            'pass_threshold' => 80,
            'time_limit_minutes' => 30,
            'question_count' => count($correctFlags),
            'max_attempts_per_window' => 3,
            'attempt_window_days' => 30,
            'validity_months' => 12,
            'status' => 'published',
            'randomize_questions' => false,
            'randomize_options' => false,
        ], $overrides));

        foreach ($correctFlags as $index => $flag) {
            $question = $certification->questions()->create([
                'kind' => $index % 2 === 0 ? 'mcq' : 'scenario',
                'prompt' => 'Question ' . ($index + 1),
                'scenario_context' => $index % 2 === 0 ? null : 'Customer says: maybe later.',
                'explanation' => 'Because it matches the SOP.',
                'topic_tag' => $index % 2 === 0 ? 'Discovery' : 'Renewals',
                'weight' => 1,
                'order' => $index + 1,
            ]);
            $question->options()->create(['text' => 'Correct', 'is_correct' => true, 'order' => 1]);
            $question->options()->create(['text' => 'Wrong', 'is_correct' => false, 'order' => 2]);
        }

        return $certification;
    }

    private function userForRole(string $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'status' => 'active',
            'assigned_market_ids' => [],
        ]);
    }
}
