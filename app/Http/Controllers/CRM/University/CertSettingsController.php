<?php

namespace App\Http\Controllers\CRM\University;

use App\Http\Controllers\Controller;
use App\Models\University\Certification;
use App\Models\University\Question;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CertSettingsController extends Controller
{
    use SerializesUniversityPayloads;

    public function store(Request $request)
    {
        $validated = $this->validateCertification($request);
        $validated['slug'] = $validated['slug'] ?? Str::slug($validated['title']);

        $certification = Certification::create($validated);

        return response()->json([
            'message' => 'Certification created.',
            'certification' => $this->serializeCertification($certification->fresh('questions.options')),
        ], 201);
    }

    public function update(Request $request, Certification $certification)
    {
        $validated = $this->validateCertification($request, partial: true);
        if (isset($validated['title']) && empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['title']);
        }
        $certification->update($validated);

        return response()->json([
            'message' => 'Certification settings updated.',
            'certification' => $this->serializeCertification($certification->fresh('questions.options')),
        ]);
    }

    public function questions(Certification $certification)
    {
        return response()->json([
            'questions' => $certification->questions()->with('options')->get()->map(fn (Question $question) => $this->serializeQuestion($question, true))->values(),
        ]);
    }

    public function storeQuestion(Request $request, Certification $certification)
    {
        $validated = $this->validateQuestion($request);
        $question = DB::transaction(function () use ($certification, $validated) {
            $options = $validated['options'];
            unset($validated['options']);
            $question = $certification->questions()->create($validated);
            $this->syncOptions($question, $options);

            return $question->fresh('options');
        });

        return response()->json([
            'message' => 'Question created.',
            'question' => $this->serializeQuestion($question, true),
        ], 201);
    }

    public function updateQuestion(Request $request, Question $question)
    {
        $validated = $this->validateQuestion($request, partial: true);
        $question = DB::transaction(function () use ($question, $validated) {
            $options = $validated['options'] ?? null;
            unset($validated['options']);
            $question->update($validated);
            if (is_array($options)) {
                $this->syncOptions($question, $options);
            }

            return $question->fresh('options');
        });

        return response()->json([
            'message' => 'Question updated.',
            'question' => $this->serializeQuestion($question, true),
        ]);
    }

    public function destroyQuestion(Question $question)
    {
        $question->delete();

        return response()->json(['message' => 'Question deleted.']);
    }

    private function validateCertification(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'course_id' => ['nullable', 'integer', 'exists:university_courses,id'],
            'title' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'slug' => [$partial ? 'sometimes' : 'nullable', 'string', 'max:180'],
            'description' => ['nullable', 'string'],
            'pass_threshold' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'time_limit_minutes' => ['sometimes', 'integer', 'min:1', 'max:240'],
            'question_count' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'max_attempts_per_window' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'attempt_window_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'validity_months' => ['sometimes', 'integer', 'min:1', 'max:120'],
            'randomize_questions' => ['sometimes', 'boolean'],
            'randomize_options' => ['sometimes', 'boolean'],
            'show_explanations_on_fail' => ['sometimes', 'boolean'],
            'allow_review_before_submit' => ['sometimes', 'boolean'],
            'cert_template_id' => ['nullable', 'string', 'max:120'],
            'status' => ['sometimes', 'in:draft,published,archived'],
        ]);
    }

    private function validateQuestion(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'kind' => ['sometimes', 'in:mcq,scenario'],
            'prompt' => [$partial ? 'sometimes' : 'required', 'string'],
            'scenario_context' => ['nullable', 'string'],
            'explanation' => ['nullable', 'string'],
            'topic_tag' => ['nullable', 'string', 'max:120'],
            'weight' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'order' => ['sometimes', 'integer', 'min:0'],
            'options' => [$partial ? 'sometimes' : 'required', 'array', 'min:2'],
            'options.*.id' => ['sometimes', 'integer'],
            'options.*.text' => ['required_with:options', 'string'],
            'options.*.is_correct' => ['sometimes', 'boolean'],
            'options.*.order' => ['sometimes', 'integer', 'min:0'],
        ]);
    }

    private function syncOptions(Question $question, array $options): void
    {
        $question->options()->delete();
        foreach (array_values($options) as $index => $option) {
            $question->options()->create([
                'text' => $option['text'],
                'is_correct' => (bool) ($option['is_correct'] ?? false),
                'order' => $option['order'] ?? ($index + 1),
            ]);
        }
    }
}
