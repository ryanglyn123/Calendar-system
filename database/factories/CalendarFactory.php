<?php

namespace Database\Factories;

use App\Enums\CalendarType;
use App\Models\Calendar;
use App\Models\Division;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Calendar>
 */
class CalendarFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type' => CalendarType::Personal,
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'color' => fake()->hexColor(),
            'owner_id' => User::factory(),
            'division_id' => null,
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function organization(bool $default = true): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => CalendarType::Organization,
            'owner_id' => null,
            'is_default' => $default,
        ]);
    }

    public function division(?Division $division = null): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => CalendarType::Division,
            'owner_id' => null,
            'division_id' => $division->id ?? Division::factory(),
        ]);
    }

    public function resource(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => CalendarType::Resource,
            'owner_id' => null,
        ]);
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'owner_id' => $user->id,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
