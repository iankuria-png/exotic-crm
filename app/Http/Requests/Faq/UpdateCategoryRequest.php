<?php

namespace App\Http\Requests\Faq;

use App\Models\Faq\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var Category|null $category */
        $category = $this->route('category');

        return [
            'slug' => ['sometimes', 'string', 'max:160', 'alpha_dash', Rule::unique('faq_categories', 'slug')->ignore($category?->id)],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'crm_page' => ['nullable', Rule::in(['dashboard', 'clients', 'client_detail', 'deals', 'payments', 'conversations', 'campaigns', 'leads', 'cross_cutting', 'team'])],
            'position' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
