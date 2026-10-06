<?php

namespace App\Http\Requests\Booking;

use App\Services\Booking\PeopleList;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A requester correcting their own request while it waits for review: its
 * title, purpose and people. Time and room stay put; changing those means a
 * new request, since the slot is what the approver weighs.
 */
class ReviseBookingRequest extends FormRequest
{
    use ValidatesPeople;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('booking'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'purpose' => ['nullable', 'string', 'max:2000'],
            ...PeopleList::rules(),
            'version' => ['nullable', 'string'],
        ];
    }
}
