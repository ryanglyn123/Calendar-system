<?php

namespace Database\Seeders;

use App\Models\EventType;
use Illuminate\Database\Seeder;

class EventTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['name' => 'Meeting', 'color' => '#0096E2', 'icon' => 'users'],
            ['name' => 'Festival', 'color' => '#E200A9', 'icon' => 'clapperboard'],
            ['name' => 'Screening', 'color' => '#580076', 'icon' => 'film'],
            ['name' => 'Training', 'color' => '#10B981', 'icon' => 'graduation-cap'],
            ['name' => 'Deadline', 'color' => '#DC2626', 'icon' => 'alarm-clock'],
            ['name' => 'Travel', 'color' => '#F59E0B', 'icon' => 'plane'],
        ];

        foreach ($types as $index => $type) {
            EventType::query()->firstOrCreate(
                ['name' => $type['name']],
                [...$type, 'sort_order' => $index, 'is_active' => true],
            );
        }
    }
}
