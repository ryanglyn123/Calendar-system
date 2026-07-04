<?php

namespace Database\Seeders;

use App\Models\Division;
use Illuminate\Database\Seeder;

class DivisionSeeder extends Seeder
{
    public function run(): void
    {
        $divisions = [
            ['name' => 'Office of the Chairperson and CEO', 'acronym' => 'OCC', 'color' => '#EFD95F'],
            ['name' => 'Administrative Division', 'acronym' => 'ADMIN', 'color' => '#86C6F5'],
            ['name' => 'Finance Division', 'acronym' => 'FIN', 'color' => '#86DBAE'],
            ['name' => 'Legal Division', 'acronym' => 'LEGAL', 'color' => '#BFA8F5'],
            ['name' => 'Policy and Planning Division', 'acronym' => 'PPD', 'color' => '#F5A6D8'],
            ['name' => 'Philippine Film Archive', 'acronym' => 'PFA', 'color' => '#F2BE8E'],
            ['name' => 'Festivals and Exhibitions Division', 'acronym' => 'FED', 'color' => '#F7A79B'],
            ['name' => 'Film Education and Grants Division', 'acronym' => 'FEGD', 'color' => '#B29AF5'],
            ['name' => 'Communications and Creative Division', 'acronym' => 'CCD', 'color' => '#91D6E4'],
            ['name' => 'Management Information Systems Division', 'acronym' => 'MIS', 'color' => '#AFBECE'],
        ];

        foreach ($divisions as $index => $division) {
            Division::query()->firstOrCreate(
                ['name' => $division['name']],
                [...$division, 'sort_order' => $index, 'is_active' => true],
            );
        }
    }
}
