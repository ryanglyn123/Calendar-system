<?php

namespace App\Http\Requests\Booking;

use App\Services\Booking\PeopleList;
use Illuminate\Validation\Validator;

/**
 * Booking forms that carry a people list: tidy the names first, validate
 * them with the shared rules, and point a repeated name at its own row.
 */
trait ValidatesPeople
{
    protected function prepareForValidation(): void
    {
        if ($this->has('people')) {
            $this->merge(['people' => PeopleList::normalize($this->input('people'))]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return PeopleList::messages();
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => PeopleList::flagDuplicates($validator, $this->input('people'))];
    }
}
