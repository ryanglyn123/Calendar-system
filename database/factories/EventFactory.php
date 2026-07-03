<?php

namespace Database\Factories;

use App\Enums\EventStatus;
use App\Models\Calendar;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    public function definition(): array
    {
        $start = Carbon::now()->addDays(fake()->numberBetween(-10, 20))->setTime(fake()->numberBetween(8, 16), 0);

        return [
            'calendar_id' => Calendar::factory(),
            'event_type_id' => null,
            'created_by' => User::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->paragraph(),
            'location' => fake()->optional()->city(),
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHours(fake()->numberBetween(1, 3)),
            'all_day' => false,
            'timezone' => 'Asia/Manila',
            'status' => EventStatus::Confirmed,
            'color' => null,
            'visibility' => 'default',
        ];
    }

    public function allDay(): static
    {
        return $this->state(function (array $attributes) {
            $start = Carbon::parse($attributes['starts_at'])->startOfDay();

            return [
                'all_day' => true,
                'starts_at' => $start,
                'ends_at' => $start->copy()->addDay(),
            ];
        });
    }

    public function private(): static
    {
        return $this->state(fn (array $attributes) => [
            'visibility' => 'private',
        ]);
    }

    public function at(Carbon $start, int $durationMinutes = 60): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => $start,
            'ends_at' => $start->copy()->addMinutes($durationMinutes),
        ]);
    }

    public function recurring(string $rrule = 'FREQ=WEEKLY'): static
    {
        return $this->state(fn (array $attributes) => [
            'rrule' => $rrule,
        ]);
    }
}
