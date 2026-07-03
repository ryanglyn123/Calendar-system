<?php

namespace App\Http\Requests\Event;

use App\Models\Calendar;
use App\Models\Event;
use App\Rules\ValidRrule;
use App\Services\Calendar\CalendarAccessService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateEventRequest extends FormRequest
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
            'calendar_id' => ['sometimes', 'integer', Rule::exists('calendars', 'id')->whereNull('deleted_at')],
            'event_type_id' => ['sometimes', 'nullable', 'integer', Rule::exists('event_types', 'id')->where('is_active', true)],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'meeting_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'starts_at' => ['sometimes', 'required', 'date'],
            'ends_at' => ['sometimes', 'required', 'date'],
            'all_day' => ['sometimes', 'boolean'],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'visibility' => ['sometimes', Rule::in(['default', 'private'])],
            'rrule' => ['sometimes', 'nullable', 'string', 'max:500', new ValidRrule],
        ];
    }

    /**
     * @return list<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                /** @var Event $event */
                $event = $this->route('event');

                $starts = $this->date('starts_at') ?? $event->starts_at;
                $ends = $this->date('ends_at') ?? $event->ends_at;

                if ($ends <= $starts) {
                    $validator->errors()->add('ends_at', 'The end must be after the start.');
                }

                if ($this->has('calendar_id') && ! $validator->errors()->has('calendar_id')) {
                    $calendar = Calendar::query()->find($this->integer('calendar_id'));

                    if ($calendar !== null && ! app(CalendarAccessService::class)->canWriteEvents($this->user(), $calendar)) {
                        $validator->errors()->add('calendar_id', 'You cannot move events to this calendar.');
                    }
                }
            },
        ];
    }
}
