<?php

namespace App\Http\Requests\Admin;

use App\Models\Division;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDivisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Division::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('divisions', 'name')->whereNull('deleted_at')],
            // Units are one level deep: a parent must itself be a root division.
            'parent_id' => [
                'nullable', 'integer',
                Rule::exists('divisions', 'id')->whereNull('deleted_at')->whereNull('parent_id'),
            ],
            'acronym' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:1000'],
            'color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'is_active' => ['boolean'],
        ];
    }
}
