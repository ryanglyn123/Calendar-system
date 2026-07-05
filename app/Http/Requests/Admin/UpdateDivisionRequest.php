<?php

namespace App\Http\Requests\Admin;

use App\Models\Division;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDivisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('division'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Division $division */
        $division = $this->route('division');

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('divisions', 'name')->ignore($division)->whereNull('deleted_at'),
            ],
            // Units are one level deep: a parent must be a root division,
            // never itself, and a division with units cannot become a unit.
            'parent_id' => [
                'nullable', 'integer',
                Rule::exists('divisions', 'id')->whereNull('deleted_at')->whereNull('parent_id'),
                Rule::notIn([$division->id]),
                function (string $attribute, mixed $value, \Closure $fail) use ($division) {
                    if ($value !== null && $division->children()->exists()) {
                        $fail('This division has units; it cannot become a unit itself.');
                    }
                },
            ],
            'acronym' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:1000'],
            'color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'is_active' => ['boolean'],
        ];
    }
}
