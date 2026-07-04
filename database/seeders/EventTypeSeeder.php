<?php

namespace Database\Seeders;

use App\Models\EventType;
use Illuminate\Database\Seeder;

class EventTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['name' => 'Meeting', 'color' => '#86C6F5', 'icon' => 'users'],
            ['name' => 'Festival', 'color' => '#F5A6D8', 'icon' => 'clapperboard'],
            ['name' => 'Screening', 'color' => '#BFA8F5', 'icon' => 'film'],
            ['name' => 'Training', 'color' => '#86DBAE', 'icon' => 'graduation-cap'],
            ['name' => 'Deadline', 'color' => '#F7A79B', 'icon' => 'alarm-clock'],
            ['name' => 'Travel', 'color' => '#F5CB81', 'icon' => 'plane'],
        ];

        foreach ($types as $index => $type) {
            EventType::query()->firstOrCreate(
                ['name' => $type['name']],
                [...$type, 'sort_order' => $index, 'is_active' => true],
            );
        }
    }
}
