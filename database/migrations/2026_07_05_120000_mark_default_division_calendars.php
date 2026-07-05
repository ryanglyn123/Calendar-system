<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The earliest division calendar is the division's own; marking it
     * is_default separates it from member-managed unit calendars.
     */
    public function up(): void
    {
        $defaults = DB::table('calendars')
            ->where('type', 'division')
            ->groupBy('division_id')
            ->selectRaw('MIN(id) as id')
            ->pluck('id');

        DB::table('calendars')->whereIn('id', $defaults)->update(['is_default' => true]);
    }

    public function down(): void
    {
        DB::table('calendars')->where('type', 'division')->update(['is_default' => false]);
    }
};
