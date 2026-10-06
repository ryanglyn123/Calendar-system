<?php

namespace Database\Seeders;

use App\Enums\CalendarType;
use App\Models\Calendar;
use App\Models\Division;
use App\Models\Event;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * FDCP's divisions as listed in the HRIS roster (see FdcpRosterSeeder).
 *
 * Installs seeded before the HRIS import carry placeholder names; those rows
 * are renamed in place (keeping their calendars and events) or folded into
 * their HRIS successor, so re-running this seeder upgrades an existing
 * database without losing data.
 */
class DivisionSeeder extends Seeder
{
    /** Acronyms are short labels for the UI; edit them in Admin → Divisions. */
    private const DIVISIONS = [
        ['name' => 'Office of the Chairperson and CEO', 'acronym' => 'OCC', 'color' => '#F6BF26'],
        ['name' => 'Office of the Executive Director', 'acronym' => 'OED', 'color' => '#F09300'],
        ['name' => 'Administrative and Finance Division', 'acronym' => 'AFD', 'color' => '#039BE5'],
        ['name' => 'Policy and Planning Division', 'acronym' => 'PPD', 'color' => '#D81B60'],
        ['name' => 'Information Technology Unit', 'acronym' => 'ITU', 'color' => '#616161', 'parent' => 'Policy and Planning Division'],
        ['name' => 'Philippine Film Archive', 'acronym' => 'PFA', 'color' => '#EF6C00'],
        ['name' => 'Festivals Division', 'acronym' => 'FESD', 'color' => '#E67C73'],
        ['name' => 'Exhibition Division', 'acronym' => 'EXD', 'color' => '#F4511E'],
        ['name' => 'Education Division', 'acronym' => 'EDU', 'color' => '#9E69AF'],
        ['name' => 'Communications Unit', 'acronym' => 'COMMS', 'color' => '#009688'],
        ['name' => 'Film Philippines Office', 'acronym' => 'FPO', 'color' => '#33B679'],
        ['name' => 'CreatePH Films', 'acronym' => 'CPH', 'color' => '#7986CB'],
        ['name' => 'Special Projects Unit', 'acronym' => 'SPU', 'color' => '#A79B8E'],
    ];

    /** Pre-HRIS placeholder name => its HRIS successor (null: deactivate). */
    private const LEGACY = [
        'Administrative Division' => 'Administrative and Finance Division',
        'Finance Division' => 'Administrative and Finance Division',
        'Festivals and Exhibitions Division' => 'Festivals Division',
        'Film Education and Grants Division' => 'Education Division',
        'Communications and Creative Division' => 'Communications Unit',
        'Management Information Systems Division' => 'Information Technology Unit',
        'Legal Division' => null,
    ];

    public function run(): void
    {
        DB::transaction(function () {
            foreach (self::LEGACY as $old => $new) {
                $this->retire($old, $new);
            }

            foreach (self::DIVISIONS as $index => $row) {
                $parentId = isset($row['parent'])
                    ? Division::query()->where('name', $row['parent'])->value('id')
                    : null;

                Division::query()->updateOrCreate(
                    ['name' => $row['name']],
                    [
                        'acronym' => $row['acronym'],
                        'color' => $row['color'],
                        'parent_id' => $parentId,
                        'sort_order' => $index,
                        'is_active' => true,
                    ],
                );
            }
        });
    }

    private function retire(string $oldName, ?string $newName): void
    {
        $old = Division::query()->where('name', $oldName)->first();

        if ($old === null) {
            return;
        }

        if ($newName === null) {
            $old->update(['is_active' => false]);

            return;
        }

        $target = Division::query()->where('name', $newName)->first();

        // First placeholder for this successor: rename it, keeping its id,
        // members, calendar and events.
        if ($target === null) {
            $old->update(['name' => $newName]);
            $this->defaultCalendar($old)?->update([
                'name' => $newName,
                'description' => "Shared calendar for {$newName}.",
            ]);

            return;
        }

        // Successor already exists: move everything over, then drop the old
        // row (its observer removes the now-empty division calendar).
        $oldCalendar = $this->defaultCalendar($old);
        $newCalendar = $this->defaultCalendar($target);

        if ($oldCalendar && $newCalendar) {
            Event::query()->where('calendar_id', $oldCalendar->id)
                ->update(['calendar_id' => $newCalendar->id]);
        }

        foreach (['users', 'invitations', 'announcements'] as $table) {
            DB::table($table)->where('division_id', $old->id)->update(['division_id' => $target->id]);
        }

        Calendar::query()->where('division_id', $old->id)->where('is_default', false)
            ->update(['division_id' => $target->id]);
        Division::query()->where('parent_id', $old->id)->update(['parent_id' => $target->id]);

        $old->delete();
    }

    private function defaultCalendar(Division $division): ?Calendar
    {
        return Calendar::query()
            ->where('type', CalendarType::Division->value)
            ->where('division_id', $division->id)
            ->where('is_default', true)
            ->first();
    }
}
