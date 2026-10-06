<?php

namespace App\Http\Requests\Event;

use Illuminate\Foundation\Http\FormRequest;

class UpdateColorLabelsRequest extends FormRequest
{
    /**
     * Everyone keeps their own labels.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The whole list, in order: each color once, an optional name, and
     * whether the color menu shows it.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'labels' => ['present', 'array', 'max:60'],
            'labels.*.color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/', 'distinct:ignore_case'],
            'labels.*.name' => ['nullable', 'string', 'max:40'],
            'labels.*.hidden' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'labels.*.color.distinct' => 'Each color can have only one label.',
        ];
    }
}
