<?php

namespace Database\Factories;

use App\Models\ResourceType;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ResourceType>
 */
class ResourceTypeFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->word().' '.fake()->word();

        return [
            'name' => ucwords($name),
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'approver_role_id' => Role::query()->where('name', 'super_admin')->value('id')
                ?? Role::create(['name' => 'approver_'.fake()->unique()->word()])->id,
            'requires_approval' => true,
            'min_lead_time_minutes' => 0,
            'max_duration_minutes' => null,
            'max_advance_days' => null,
            'max_active_bookings_per_user' => null,
            'enforce_working_hours' => false,
            'block_holidays' => false,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function autoApproved(): static
    {
        return $this->state(fn () => ['requires_approval' => false]);
    }
}
