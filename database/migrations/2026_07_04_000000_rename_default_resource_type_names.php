<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The seeded resource types now read as sidebar sections ("Boardroom",
 * "Carpool"). Only rows still carrying the old seeded names are touched,
 * so admin-customized names survive.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('resource_types')
            ->where('slug', 'board-room')
            ->where('name', 'Board Rooms')
            ->update(['name' => 'Boardroom']);

        DB::table('resource_types')
            ->where('slug', 'vehicle')
            ->where('name', 'Vehicles')
            ->update(['name' => 'Carpool']);
    }

    public function down(): void
    {
        DB::table('resource_types')
            ->where('slug', 'board-room')
            ->where('name', 'Boardroom')
            ->update(['name' => 'Board Rooms']);

        DB::table('resource_types')
            ->where('slug', 'vehicle')
            ->where('name', 'Carpool')
            ->update(['name' => 'Vehicles']);
    }
};
