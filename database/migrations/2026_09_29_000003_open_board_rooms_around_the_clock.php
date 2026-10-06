<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Board rooms host events outside office hours too, so they are bookable
 * around the clock: no working-hours window and no holiday block. Admins can
 * turn either back on per type under Settings, Resource types.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('resource_types')
            ->where('slug', 'board-room')
            ->update(['enforce_working_hours' => false, 'block_holidays' => false]);
    }

    public function down(): void
    {
        DB::table('resource_types')
            ->where('slug', 'board-room')
            ->update(['enforce_working_hours' => true, 'block_holidays' => true]);
    }
};
