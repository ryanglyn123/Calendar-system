<?php

namespace Database\Seeders;

use App\Models\EventType;
use Illuminate\Database\Seeder;

class EventTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['name' => 'Meeting', 'color' => '#039BE5', 'icon' => 'users'],
            ['name' => 'Festival', 'color' => '#D81B60', 'icon' => 'clapperboard'],
            ['name' => 'Screening', 'color' => '#7986CB', 'icon' => 'film'],
            ['name' => 'Training', 'color' => '#33B679', 'icon' => 'graduation-cap'],
            ['name' => 'Deadline', 'color' => '#E67C73', 'icon' => 'alarm-clock'],
            ['name' => 'Travel', 'color' => '#F09300', 'icon' => 'plane'],
        ];

        foreach ($types as $index => $type) {
            EventType::query()->firstOrCreate(
                ['name' => $type['name']],
                [...$type, 'sort_order' => $index, 'is_active' => true],
            );
        }
    }
}
