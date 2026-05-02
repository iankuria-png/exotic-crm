<?php

namespace App\Http\Requests\Faq;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'article_id' => ['nullable', 'exists:faq_articles,id'],
            'kind' => ['required', Rule::in(['helpful', 'unhelpful', 'article_suggestion', 'bug', 'feature_request', 'general'])],
            'helpful' => ['nullable', 'boolean'],
            'title' => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'string'],
            'severity' => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
            'context_path' => ['nullable', 'string', 'max:2048'],
            'context_meta' => ['nullable', 'array'],
            'screenshot' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'status' => ['nullable', Rule::in(['new', 'triaged', 'planned', 'in_progress', 'shipped', 'resolved', 'wontfix', 'duplicate'])],
        ];
    }
}
