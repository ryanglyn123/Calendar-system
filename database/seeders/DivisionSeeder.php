<?php

namespace Database\Seeders;

use App\Models\Division;
use Illuminate\Database\Seeder;

class DivisionSeeder extends Seeder
{
    public function run(): void
    {
        $divisions = [
            ['name' => 'Office of the Chairperson and CEO', 'acronym' => 'OCC', 'color' => '#EEE621'],
            ['name' => 'Administrative Division', 'acronym' => 'ADMIN', 'color' => '#0096E2'],
            ['name' => 'Finance Division', 'acronym' => 'FIN', 'color' => '#10B981'],
            ['name' => 'Legal Division', 'acronym' => 'LEGAL', 'color' => '#580076'],
            ['name' => 'Policy and Planning Division', 'acronym' => 'PPD', 'color' => '#E200A9'],
            ['name' => 'Philippine Film Archive', 'acronym' => 'PFA', 'color' => '#B45309'],
            ['name' => 'Festivals and Exhibitions Division', 'acronym' => 'FED', 'color' => '#DC2626'],
            ['name' => 'Film Education and Grants Division', 'acronym' => 'FEGD', 'color' => '#7C3AED'],
            ['name' => 'Communications and Creative Division', 'acronym' => 'CCD', 'color' => '#0891B2'],
            ['name' => 'Management Information Systems Division', 'acronym' => 'MIS', 'color' => '#475569'],
        ];

        foreach ($divisions as $index => $division) {
            Division::query()->firstOrCreate(
                ['name' => $division['name']],
                [...$division, 'sort_order' => $index, 'is_active' => true],
            );
        }
    }
}
