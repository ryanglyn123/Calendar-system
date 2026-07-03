<?php

namespace Database\Factories;

use App\Models\EventType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventType>
 */
class EventTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'color' => fake()->hexColor(),
            'icon' => null,
            'description' => fake()->sentence(),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
