<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The seeded demo vehicles become FDCP's real carpool. Only rows still
 * carrying the old seeded names are touched, so admin edits survive, and the
 * vehicle's calendar mirrors its name, so that is renamed too. The Tamaraw and
 * Fortuner are new; ResourceSeeder adds them.
 */
return new class extends Migration
{
    /** old name => [new name, sort order] */
    private const VEHICLES = [
        'Toyota Hiace (SKB 1234)' => ['Toyota Hiace Van 1', 0],
        'Mitsubishi L300 (UAM 9012)' => ['Toyota Hiace Van 2', 1],
        'Toyota Innova (NEd 5678)' => ['Toyota Innova', 2],
    ];

    public function up(): void
    {
        $typeId = DB::table('resource_types')->where('slug', 'vehicle')->value('id');

        foreach (self::VEHICLES as $old => [$name, $order]) {
            $vehicle = DB::table('resources')
                ->where('resource_type_id', $typeId)
                ->where('name', $old)
                ->first();

            if (! $vehicle) {
                continue;
            }

            DB::table('resources')->where('id', $vehicle->id)->update([
                'name' => $name,
                'capacity' => null,
                'sort_order' => $order,
            ]);

            DB::table('calendars')->where('id', $vehicle->calendar_id)->update(['name' => $name]);
        }
    }

    public function down(): void
    {
        // The old names were demo data; nothing worth restoring.
    }
};
