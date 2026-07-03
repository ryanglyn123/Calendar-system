<?php

namespace Database\Factories;

use App\Models\Calendar;
use App\Models\ResourceType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Resource>
 */
class ResourceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'resource_type_id' => ResourceType::factory(),
            'calendar_id' => Calendar::factory()->resource(),
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->sentence(),
            'location' => fake()->optional()->city(),
            'capacity' => fake()->optional()->numberBetween(10, 30),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
