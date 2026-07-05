<?php

namespace App\Http\Controllers\Calendar;

use App\Enums\CalendarType;
use App\Http\Controllers\Controller;
use App\Models\Calendar;
use App\Models\Division;
use App\Models\User;
use App\Services\Calendar\CalendarService;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Units are real divisions one level under a parent division (own members
 * and calendar; they share the parent's chat room). Admins manage them in
 * Settings > Divisions; these routes let division members manage the units
 * of their OWN division: explicit division checks, with divisions.manage
 * passing admins (Gate::before covers super admins).
 */
class DivisionUnitController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $division = $user->division;

        abort_if($division === null, 403, 'Join a division first.');
        abort_if($division->isUnit(), 403, 'Units cannot contain units.');

        $validated = $request->validate($this->rules());

        // The observer creates the unit's calendar and busts caches.
        Division::query()->create([
            ...$validated,
            'parent_id' => $division->id,
            'is_active' => true,
        ]);

        Toast::success('Unit created.');

        return back();
    }

    public function update(Request $request, Division $division): RedirectResponse
    {
        $this->assertUnitManageable($request->user(), $division);

        $validated = $request->validate($this->rules($division));

        $division->update($validated);

        // Keep the unit's calendar in step with its division.
        Calendar::query()
            ->where('type', CalendarType::Division->value)
            ->where('division_id', $division->id)
            ->where('is_default', true)
            ->update(['name' => $validated['name'], 'color' => $validated['color']]);

        Toast::success('Unit updated.');

        return back();
    }

    public function destroy(Request $request, Division $division, CalendarService $calendars): RedirectResponse
    {
        $this->assertUnitManageable($request->user(), $division);

        abort_if($division->users()->exists(), 403, 'Units with members cannot be deleted. Reassign them first.');

        foreach (Calendar::query()->where('division_id', $division->id)->get() as $calendar) {
            $calendars->delete($calendar);
        }

        $division->delete();

        Toast::success('Unit deleted.');

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?Division $ignore = null): array
    {
        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('divisions', 'name')->ignore($ignore)->whereNull('deleted_at'),
            ],
            'color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }

    /**
     * Members may manage units of their own division (or their own unit);
     * divisions.manage covers admins and super admins.
     */
    private function assertUnitManageable(User $user, Division $division): void
    {
        abort_unless(
            $division->isUnit()
            && ($division->parent_id === $user->division_id
                || $division->id === $user->division_id
                || $user->can('divisions.manage')),
            403,
        );
    }
}
