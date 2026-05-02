<?php

namespace App\Http\Requests\Faq;

use Illuminate\Foundation\Http\FormRequest;

class StoreWalkthroughRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'max:180', 'alpha_dash', 'unique:faq_walkthroughs,slug'],
            'name' => ['required', 'string', 'max:255'],
            'steps' => ['required', 'array', 'min:1'],
            'steps.*.element_selector' => ['required', 'string', 'max:255'],
            'steps.*.title' => ['required', 'string', 'max:255'],
            'steps.*.body' => ['required', 'string'],
            'steps.*.position' => ['nullable', 'integer', 'min:0'],
            'steps.*.side' => ['nullable', 'string', 'max:30'],
            'steps.*.align' => ['nullable', 'string', 'max:30'],
        ];
    }
}
