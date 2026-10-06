<?php

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Holiday;
use App\Models\Resource;
use App\Models\User;
use App\Models\WorkingHour;
use App\Settings\GeneralSettings;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * Enforces the per-resource-type booking policy the admin configured.
 * Every rule that exists in the admin panel is enforced here, by design.
 */
class BookingRuleValidator
{
    public function validate(
        User $user,
        Resource $resource,
        CarbonInterface $start,
        CarbonInterface $end,
        ?Booking $ignoreBooking = null,
    ): void {
        $type = $resource->type;

        if (! $resource->is_active || ! $type->is_active) {
            $this->fail('This resource is not currently bookable.');
        }

        if ($start->isPast()) {
            $this->fail('Bookings cannot start in the past.');
        }

        if ($type->min_lead_time_minutes > 0 && now()->diffInMinutes($start, false) < $type->min_lead_time_minutes) {
            $this->fail("Bookings need at least {$type->min_lead_time_minutes} minutes of lead time.");
        }

        if ($type->max_duration_minutes !== null && $start->diffInMinutes($end) > $type->max_duration_minutes) {
            $this->fail("Bookings of this type are limited to {$type->max_duration_minutes} minutes.");
        }

        if ($type->max_advance_days !== null && now()->diffInDays($start, false) > $type->max_advance_days) {
            $this->fail("Bookings can be made at most {$type->max_advance_days} days in advance.");
        }

        if ($type->max_active_bookings_per_user !== null) {
            $active = Booking::query()
                ->where('requested_by', $user->id)
                ->whereRelation('resource', 'resource_type_id', $type->id)
                ->whereIn('status', [BookingStatus::Pending->value, BookingStatus::Approved->value])
                ->where('ends_at', '>', now())
                ->when($ignoreBooking, fn ($q) => $q->whereKeyNot($ignoreBooking->id))
                ->lockForUpdate()
                ->count();

            if ($active >= $type->max_active_bookings_per_user) {
                $this->fail("You already have {$active} active booking(s) of this type; the limit is {$type->max_active_bookings_per_user}.");
            }
        }

        // Holidays are office days: 07:00 Manila on a holiday is still 23:00 UTC the day before.
        if ($type->block_holidays && ($holiday = Holiday::blockingWithin($start, $end)) !== null) {
            $this->fail("{$holiday->name} is a holiday; this resource cannot be booked then.");
        }

        if ($type->enforce_working_hours) {
            $this->assertWithinWorkingHours($resource, $start, $end);
        }
    }

    private function assertWithinWorkingHours(Resource $resource, CarbonInterface $start, CarbonInterface $end): void
    {
        $office = app(GeneralSettings::class)->timezone;
        $local = $start->avoidMutation()->timezone($office);
        $localEnd = $end->avoidMutation()->timezone($office);

        if (! $local->isSameDay($localEnd)) {
            $this->fail('Bookings must start and end on the same day when working hours apply.');
        }

        $schedule = WorkingHour::for($resource->id, $local->dayOfWeek);

        if ($schedule === null || $schedule->isClosed()) {
            $this->fail('The resource is closed on the selected day.');
        }

        $opens = $local->avoidMutation()->setTimeFromTimeString((string) $schedule->opens_at);
        $closes = $local->avoidMutation()->setTimeFromTimeString((string) $schedule->closes_at);

        if ($local < $opens || $localEnd > $closes) {
            $this->fail(sprintf(
                'Bookings are limited to %s to %s on that day.',
                $opens->format('H:i'),
                $closes->format('H:i'),
            ));
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['starts_at' => $message]);
    }
}
