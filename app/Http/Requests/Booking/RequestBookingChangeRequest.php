<?php

namespace App\Http\Requests\Booking;

use App\Services\Booking\PeopleList;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A change proposed to an approved booking; an approver applies or rejects
 * it. Fields left out stay as they are.
 */
class RequestBookingChangeRequest extends FormRequest
{
    use ValidatesPeople;

    public function authorize(): bool
    {
        return $this->user()->can('requestEdit', $this->route('booking'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'required_with:starts_at', 'after:starts_at'],
            'purpose' => ['nullable', 'string', 'max:2000'],
            ...PeopleList::rules(required: false),
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
