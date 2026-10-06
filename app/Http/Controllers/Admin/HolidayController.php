<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use App\Settings\GeneralSettings;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class HolidayController extends Controller
{
    public function index(GeneralSettings $settings): Response
    {
        abort_unless(request()->user()->can('holidays.manage'), 403);

        return Inertia::render('admin/holidays/Index', [
            'holidays' => Holiday::query()
                ->orderBy('date')
                ->get()
                ->map(fn (Holiday $holiday) => [
                    'id' => $holiday->id,
                    'name' => $holiday->name,
                    'date' => $holiday->date->toDateString(),
                    'recurs_annually' => $holiday->recurs_annually,
                    'type' => $holiday->type,
                    'blocks_bookings' => $holiday->blocks_bookings,
                ]),
            'weekStartsOn' => $settings->week_starts_on,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('holidays.manage'), 403);

        Holiday::query()->create($this->validated($request));

        Toast::success('Holiday added.');

        return back();
    }

    public function update(Request $request, Holiday $holiday): RedirectResponse
    {
        abort_unless($request->user()->can('holidays.manage'), 403);

        $holiday->update($this->validated($request));

        Toast::success('Holiday updated.');

        return back();
    }

    public function destroy(Holiday $holiday): RedirectResponse
    {
        abort_unless(request()->user()->can('holidays.manage'), 403);

        $holiday->delete();

        Toast::success('Holiday removed.');

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'recurs_annually' => ['boolean'],
            'type' => ['required', Rule::in(['regular', 'special_non_working', 'company'])],
            'blocks_bookings' => ['boolean'],
        ]);
    }
}
