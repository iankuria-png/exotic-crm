<?php

namespace App\Http\Requests\Faq;

use App\Models\Faq\Article;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var Article|null $article */
        $article = $this->route('article');

        return [
            'category_id' => ['sometimes', 'exists:faq_categories,id'],
            'slug' => ['sometimes', 'string', 'max:180', 'alpha_dash', Rule::unique('faq_articles', 'slug')->ignore($article?->id)],
            'title' => ['sometimes', 'string', 'max:255'],
            'summary' => ['nullable', 'string'],
            'body' => ['nullable', 'string'],
            'body_draft' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['draft', 'published', 'archived'])],
            'position' => ['nullable', 'integer', 'min:0'],
            'ctas' => ['nullable', 'array'],
            'ctas.*.id' => ['nullable', 'integer', 'exists:faq_article_ctas,id'],
            'ctas.*.kind' => ['required_with:ctas', Rule::in(['deep_link', 'prefill', 'walkthrough'])],
            'ctas.*.label' => ['required_with:ctas', 'string', 'max:255'],
            'ctas.*.target_path' => ['nullable', 'string', 'max:2048'],
            'ctas.*.prefill_payload' => ['nullable', 'array'],
            'ctas.*.walkthrough_id' => ['nullable', 'string', 'max:255', 'exists:faq_walkthroughs,slug'],
            'ctas.*.position' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
