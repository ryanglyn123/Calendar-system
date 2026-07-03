<?php

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Event;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    public function definition(): array
    {
        $start = Carbon::now()->addDays(fake()->numberBetween(1, 10))->setTime(fake()->numberBetween(8, 15), 0);

        return [
            'resource_id' => Resource::factory(),
            'event_id' => Event::factory(),
            'requested_by' => User::factory(),
            'status' => BookingStatus::Pending,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHours(2),
            'purpose' => fake()->sentence(),
            'attendee_count' => fake()->numberBetween(1, 10),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => BookingStatus::Approved]);
    }

    public function at(Carbon $start, int $hours = 2): static
    {
        return $this->state(fn () => [
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHours($hours),
        ]);
    }
}
