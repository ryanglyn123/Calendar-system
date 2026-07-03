<?php

namespace App\Http\Requests\Event;

use App\Models\Calendar;
use App\Rules\ValidRrule;
use App\Services\Calendar\CalendarAccessService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'calendar_id' => ['required', 'integer', Rule::exists('calendars', 'id')->whereNull('deleted_at')],
            'event_type_id' => ['nullable', 'integer', Rule::exists('event_types', 'id')->where('is_active', true)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'location' => ['nullable', 'string', 'max:255'],
            'meeting_url' => ['nullable', 'url', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'all_day' => ['boolean'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'visibility' => ['sometimes', Rule::in(['default', 'private'])],
            'rrule' => ['nullable', 'string', 'max:500', new ValidRrule],
            'attendees' => ['sometimes', 'array', 'max:100'],
            'attendees.*' => ['integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'reminders' => ['sometimes', 'array', 'max:5'],
            'reminders.*' => ['integer', 'min:0', 'max:20160'],
        ];
    }

    /**
     * @return list<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('calendar_id')) {
                    return;
                }

                $calendar = Calendar::query()->find($this->integer('calendar_id'));

                if ($calendar === null) {
                    return;
                }

                if (! app(CalendarAccessService::class)->canWriteEvents($this->user(), $calendar)) {
                    $validator->errors()->add('calendar_id', 'You cannot add events to this calendar.');
                }
            },
        ];
    }
}
