<?php

namespace App\Http\Requests\Calendar;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCalendarPreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('calendar'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'is_visible' => ['sometimes', 'nullable', 'boolean'],
            'color_override' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
