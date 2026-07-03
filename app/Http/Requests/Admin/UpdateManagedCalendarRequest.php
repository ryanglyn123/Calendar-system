<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateManagedCalendarRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'icon' => ['nullable', 'string', 'max:50'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0', 'max:65535'],
        ];
    }
}
