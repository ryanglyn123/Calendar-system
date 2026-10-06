<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The seeded demo board rooms become FDCP's real ones. Only rows still
 * carrying the old seeded names are touched, so admin edits survive. The
 * room's calendar mirrors its name, so that is renamed too. The Mezzanine
 * room is new; ResourceSeeder adds it.
 */
return new class extends Migration
{
    /** old name => [new name, location, sort order] */
    private const ROOMS = [
        'Screening Room A' => ['1st Floor Board Room', '1st Floor', 0],
        'Huddle Room' => ['Occeo Board Room', '2nd Floor', 2],
        'Cinema Boardroom' => ['3rd Floor Board Room (Main)', '3rd Floor', 3],
    ];

    public function up(): void
    {
        $typeId = DB::table('resource_types')->where('slug', 'board-room')->value('id');

        foreach (self::ROOMS as $old => [$name, $location, $order]) {
            $room = DB::table('resources')
                ->where('resource_type_id', $typeId)
                ->where('name', $old)
                ->first();

            if (! $room) {
                continue;
            }

            DB::table('resources')->where('id', $room->id)->update([
                'name' => $name,
                'location' => $location,
                'capacity' => null,
                'sort_order' => $order,
            ]);

            DB::table('calendars')->where('id', $room->calendar_id)->update(['name' => $name]);
        }
    }

    public function down(): void
    {
        // The old names were demo data; nothing worth restoring.
    }
};
