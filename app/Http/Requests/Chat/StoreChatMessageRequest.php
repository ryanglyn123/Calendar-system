<?php

namespace App\Http\Requests\Chat;

use App\Models\ChatMessage;
use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreChatMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->division_id !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $divisionId = $this->user()->division_id;

        return [
            'body' => ['nullable', 'string', 'max:4000', 'required_without_all:photos,poll,event_id'],
            'event_id' => [
                'nullable',
                'integer',
                Rule::exists(Event::class, 'id')->whereNull('deleted_at'),
            ],
            'reply_to_id' => [
                'nullable',
                'integer',
                Rule::exists(ChatMessage::class, 'id')->where('division_id', $divisionId),
            ],
            'photos' => ['nullable', 'array', 'max:4', 'prohibits:poll'],
            'photos.*' => ['image', 'mimes:jpeg,png,webp,gif', 'max:5120'],
            'poll' => ['nullable', 'array'],
            'poll.question' => ['required_with:poll', 'string', 'max:255'],
            'poll.allows_multiple' => ['sometimes', 'boolean'],
            'poll.options' => ['required_with:poll', 'array', 'min:2', 'max:10'],
            'poll.options.*' => ['required', 'string', 'max:100', 'distinct'],
        ];
    }
}
