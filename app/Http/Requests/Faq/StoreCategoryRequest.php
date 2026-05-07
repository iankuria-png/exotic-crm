<?php

namespace App\Http\Requests\Faq;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'max:160', 'alpha_dash', 'unique:faq_categories,slug'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'crm_page' => ['nullable', Rule::in(['dashboard', 'clients', 'client_detail', 'deals', 'payments', 'conversations', 'campaigns', 'leads', 'cross_cutting', 'team'])],
            'position' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
