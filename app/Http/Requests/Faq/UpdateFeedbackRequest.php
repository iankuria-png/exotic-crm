<?php

namespace App\Http\Requests\Faq;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'comment' => ['sometimes', 'nullable', 'string'],
            'severity' => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
            'status' => ['nullable', Rule::in(['new', 'triaged', 'planned', 'in_progress', 'shipped', 'resolved', 'wontfix', 'duplicate'])],
            'duplicate_of_id' => ['nullable', 'integer', 'exists:faq_feedback,id'],
            'admin_notes' => ['nullable', 'string'],
        ];
    }
}
