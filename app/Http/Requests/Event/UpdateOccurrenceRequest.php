<?php

namespace App\Http\Requests\Event;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateOccurrenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('event'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'original_starts_at' => ['required', 'date'],
            'scope' => ['required', Rule::in(['this', 'following', 'all'])],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'meeting_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            // A move needs both ends: one alone yields an occurrence that ends before it starts.
            'starts_at' => ['required_with:ends_at', 'date'],
            'ends_at' => ['required_with:starts_at', 'date'],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'event_type_id' => ['sometimes', 'nullable', 'integer', Rule::exists('event_types', 'id')->where('is_active', true)->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $this->user()->id)->orWhere('id', $this->route('event')->event_type_id))],
        ];
    }

    /**
     * @return list<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $starts = $this->date('starts_at');
                $ends = $this->date('ends_at');

                if ($starts !== null && $ends !== null && $ends <= $starts) {
                    $validator->errors()->add('ends_at', 'The end must be after the start.');
                }
            },
        ];
    }
}
