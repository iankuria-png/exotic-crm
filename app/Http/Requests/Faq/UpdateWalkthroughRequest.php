<?php

namespace App\Http\Requests\Faq;

use App\Models\Faq\Walkthrough;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWalkthroughRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var Walkthrough|null $walkthrough */
        $walkthrough = $this->route('walkthrough');

        return [
            'slug' => ['sometimes', 'string', 'max:180', 'alpha_dash', Rule::unique('faq_walkthroughs', 'slug')->ignore($walkthrough?->id)],
            'name' => ['sometimes', 'string', 'max:255'],
            'steps' => ['sometimes', 'array', 'min:1'],
            'steps.*.element_selector' => ['required_with:steps', 'string', 'max:255'],
            'steps.*.title' => ['required_with:steps', 'string', 'max:255'],
            'steps.*.body' => ['required_with:steps', 'string'],
            'steps.*.position' => ['nullable', 'integer', 'min:0'],
            'steps.*.side' => ['nullable', 'string', 'max:30'],
            'steps.*.align' => ['nullable', 'string', 'max:30'],
        ];
    }
}
