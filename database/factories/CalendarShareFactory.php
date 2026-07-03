<?php

namespace Database\Factories;

use App\Enums\SharePermission;
use App\Models\Calendar;
use App\Models\CalendarShare;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CalendarShare>
 */
class CalendarShareFactory extends Factory
{
    public function definition(): array
    {
        return [
            'calendar_id' => Calendar::factory(),
            'user_id' => User::factory(),
            'permission' => SharePermission::Viewer,
        ];
    }

    public function editor(): static
    {
        return $this->state(fn (array $attributes) => [
            'permission' => SharePermission::Editor,
        ]);
    }

    public function manager(): static
    {
        return $this->state(fn (array $attributes) => [
            'permission' => SharePermission::Manager,
        ]);
    }
}
