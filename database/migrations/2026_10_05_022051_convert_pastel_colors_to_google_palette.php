<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Event pills now look like Google Calendar's (the color solid, white text),
 * so stored colors move from the old pastel set to the nearest Google color.
 * The map keeps divisions, rooms and vehicles that were distinct distinct.
 * Colors outside the old set (custom picks) are left alone.
 */
return new class extends Migration
{
    /** Old pastel => Google color. */
    private const MAP = [
        '#EFD95F' => '#F6BF26', // lemon     → Banana
        '#F5CD7E' => '#F09300', // apricot   → Mango
        '#F5CB81' => '#F09300', // honey     → Mango
        '#86C6F5' => '#039BE5', // sky       → Peacock
        '#A8D8F0' => '#4285F4', // pale blue → Cobalt
        '#BFA8F5' => '#7986CB', // lavender  → Lavender
        '#B29AF5' => '#9E69AF', // violet    → Amethyst
        '#F5A6D8' => '#D81B60', // pink      → Cherry blossom
        '#F2BE8E' => '#EF6C00', // peach     → Pumpkin
        '#F5B98A' => '#EF6C00', // melon     → Pumpkin
        '#FFB38A' => '#F4511E', // orange    → Tangerine
        '#F7A79B' => '#E67C73', // coral     → Flamingo
        '#F0A8A8' => '#D50000', // rose      → Tomato
        '#91D6E4' => '#009688', // aqua      → Eucalyptus
        '#9BD9D0' => '#0B8043', // seafoam   → Basil
        '#86DBAE' => '#33B679', // mint      → Sage
        '#AFBECE' => '#616161', // slate     → Graphite
        '#BFC6CE' => '#616161', // silver    → Graphite
        '#D4C5A0' => '#A79B8E', // sand      → Birch
    ];

    /** Unique Google colors map back to one pastel each; shared ones pick the first. */
    private const BACK = [
        '#F6BF26' => '#EFD95F',
        '#F09300' => '#F5CD7E',
        '#039BE5' => '#86C6F5',
        '#4285F4' => '#A8D8F0',
        '#7986CB' => '#BFA8F5',
        '#9E69AF' => '#B29AF5',
        '#D81B60' => '#F5A6D8',
        '#EF6C00' => '#F2BE8E',
        '#F4511E' => '#FFB38A',
        '#E67C73' => '#F7A79B',
        '#D50000' => '#F0A8A8',
        '#009688' => '#91D6E4',
        '#0B8043' => '#9BD9D0',
        '#33B679' => '#86DBAE',
        '#616161' => '#AFBECE',
        '#A79B8E' => '#D4C5A0',
    ];

    private const TABLES = ['calendars', 'divisions', 'event_types', 'events'];

    public function up(): void
    {
        $this->remap(self::MAP);
    }

    public function down(): void
    {
        $this->remap(self::BACK);
    }

    /** @param  array<string, string>  $map */
    private function remap(array $map): void
    {
        foreach (self::TABLES as $table) {
            foreach ($map as $from => $to) {
                DB::table($table)
                    ->whereRaw('UPPER(color) = ?', [$from])
                    ->update(['color' => $to]);
            }
        }
    }
};
