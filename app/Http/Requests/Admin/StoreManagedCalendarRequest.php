<?php

namespace App\Http\Requests\Admin;

use App\Enums\CalendarType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreManagedCalendarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('calendars.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in([CalendarType::Organization->value, CalendarType::Division->value])],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'icon' => ['nullable', 'string', 'max:50'],
            'division_id' => [
                Rule::requiredIf($this->input('type') === CalendarType::Division->value),
                'nullable', 'integer', Rule::exists('divisions', 'id'),
            ],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0', 'max:65535'],
        ];
    }
}
