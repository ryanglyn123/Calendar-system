<?php

namespace Database\Seeders;

use App\Models\ResourceType;
use App\Models\Role;
use App\Models\User;
use App\Services\Booking\ResourceService;
use Illuminate\Database\Seeder;

class ResourceSeeder extends Seeder
{
    public function run(ResourceService $resources): void
    {
        $admin = User::query()->where('email', 'admin@fdcp.gov.ph')->firstOrFail();

        $boardRooms = ResourceType::query()->firstOrCreate(
            ['slug' => 'board-room'],
            [
                'name' => 'Boardroom',
                'icon' => 'presentation',
                'description' => 'Meeting and conference rooms.',
                'approver_role_id' => Role::query()->where('name', 'board_room_approver')->value('id'),
                'requires_approval' => true,
                'min_lead_time_minutes' => 30,
                'max_duration_minutes' => 480,
                'max_advance_days' => 60,
                // Events run outside office hours too: bookable around the clock.
                'enforce_working_hours' => false,
                'block_holidays' => false,
                'sort_order' => 0,
            ],
        );

        $vehicles = ResourceType::query()->firstOrCreate(
            ['slug' => 'vehicle'],
            [
                'name' => 'Carpool',
                'icon' => 'car',
                'description' => 'FDCP service vehicles.',
                'approver_role_id' => Role::query()->where('name', 'vehicle_approver')->value('id'),
                'requires_approval' => true,
                'min_lead_time_minutes' => 120,
                'max_advance_days' => 30,
                'block_holidays' => false,
                'sort_order' => 1,
            ],
        );

        $seed = [
            [$boardRooms, '1st Floor Board Room', '1st Floor', null, '#039BE5'],
            [$boardRooms, 'Mezzanine Board Room', 'Mezzanine', null, '#D50000'],
            [$boardRooms, 'Occeo Board Room', '2nd Floor', null, '#33B679'],
            [$boardRooms, '3rd Floor Board Room (Main)', '3rd Floor', null, '#7986CB'],
            [$vehicles, 'Toyota Hiace Van 1', 'Motor pool', null, '#D81B60'],
            [$vehicles, 'Toyota Hiace Van 2', 'Motor pool', null, '#616161'],
            [$vehicles, 'Toyota Innova', 'Motor pool', null, '#F6BF26'],
            [$vehicles, 'Toyota Tamaraw', 'Motor pool', null, '#EF6C00'],
            [$vehicles, 'Toyota Fortuner', 'Motor pool', null, '#0B8043'],
        ];

        foreach ($seed as $order => [$type, $name, $location, $capacity, $color]) {
            $exists = $type->resources()->where('name', $name)->exists();

            if (! $exists) {
                $resources->create([
                    'resource_type_id' => $type->id,
                    'name' => $name,
                    'location' => $location,
                    'capacity' => $capacity,
                    'color' => $color,
                    'sort_order' => $order,
                    'is_active' => true,
                ], $admin);
            }
        }
    }
}
