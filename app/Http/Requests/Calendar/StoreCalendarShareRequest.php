<?php

namespace App\Http\Requests\Calendar;

use App\Enums\SharePermission;
use App\Models\Calendar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCalendarShareRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Calendar $calendar */
        $calendar = $this->route('calendar');

        // Person-to-person sharing exists for personal calendars only;
        // managed calendars get their audience from type + division.
        return $calendar->isPersonal() && $this->user()->can('share', $calendar);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_id' => [
                'required', 'integer',
                Rule::exists('users', 'id')->whereNull('deleted_at'),
                Rule::notIn([$this->user()->id]),
            ],
            'permission' => ['required', Rule::enum(SharePermission::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.not_in' => 'You already own this calendar.',
        ];
    }
}
