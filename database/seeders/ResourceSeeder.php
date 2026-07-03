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
                'name' => 'Board Rooms',
                'icon' => 'presentation',
                'description' => 'Meeting and conference rooms.',
                'approver_role_id' => Role::query()->where('name', 'board_room_approver')->value('id'),
                'requires_approval' => true,
                'min_lead_time_minutes' => 30,
                'max_duration_minutes' => 480,
                'max_advance_days' => 60,
                'enforce_working_hours' => true,
                'block_holidays' => true,
                'sort_order' => 0,
            ],
        );

        $vehicles = ResourceType::query()->firstOrCreate(
            ['slug' => 'vehicle'],
            [
                'name' => 'Vehicles',
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
            [$boardRooms, 'Cinema Boardroom', 'Main conference room, 4F FDCP Building', 14, '#580076'],
            [$boardRooms, 'Screening Room A', '2F, with projector and 5.1 audio', 30, '#0096E2'],
            [$boardRooms, 'Huddle Room', '3F, quick syncs', 6, '#10B981'],
            [$vehicles, 'Toyota Hiace (SKB 1234)', 'Motor pool', 12, '#E200A9'],
            [$vehicles, 'Toyota Innova (NEd 5678)', 'Motor pool', 6, '#CC8500'],
            [$vehicles, 'Mitsubishi L300 (UAM 9012)', 'Motor pool', 2, '#6B7280'],
        ];

        foreach ($seed as [$type, $name, $location, $capacity, $color]) {
            $exists = $type->resources()->where('name', $name)->exists();

            if (! $exists) {
                $resources->create([
                    'resource_type_id' => $type->id,
                    'name' => $name,
                    'location' => $location,
                    'capacity' => $capacity,
                    'color' => $color,
                    'is_active' => true,
                ], $admin);
            }
        }
    }
}
