<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GoogleSyncError;
use App\Http\Controllers\Controller;
use App\Jobs\SyncGoogleHolidays;
use App\Models\Holiday;
use App\Services\Google\GoogleApiException;
use App\Services\Google\GoogleHolidaySync;
use App\Settings\GeneralSettings;
use App\Settings\GoogleCalendarSettings;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class HolidayController extends Controller
{
    public function index(GeneralSettings $settings, GoogleCalendarSettings $google): Response
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
                    'source' => $holiday->source,
                    'overridden' => $holiday->overridden,
                ]),
            'weekStartsOn' => $settings->week_starts_on,
            'google' => [
                'configured' => filled(config('services.google.calendar_api_key')),
                'calendar_ids' => $google->holiday_calendar_ids,
                'synced_at' => $google->holidays_synced_at,
                'error' => GoogleHolidaySync::lastError(),
            ],
        ]);
    }

    /** Choose which public Google holiday calendars feed the list. */
    public function updateGoogleSources(Request $request, GoogleCalendarSettings $google): RedirectResponse
    {
        abort_unless($request->user()->can('holidays.manage'), 403);

        $validated = $request->validate([
            'calendar_ids' => ['present', 'array', 'max:5'],
            // Google's public holiday calendars all live under this suffix.
            'calendar_ids.*' => ['string', 'max:200', 'distinct', 'regex:/^[a-z0-9_.\-]+#holiday@group\.v\.calendar\.google\.com$/i'],
        ], [
            'calendar_ids.*.regex' => 'Use a Google holiday calendar id, like en.philippines#holiday@group.v.calendar.google.com.',
        ]);

        $google->holiday_calendar_ids = array_values($validated['calendar_ids']);
        $google->save();

        if ($google->holiday_calendar_ids !== []) {
            SyncGoogleHolidays::dispatch();
        }

        Toast::success('Holiday sources saved.');

        return back();
    }

    /** Import now instead of waiting for the nightly run. */
    public function syncFromGoogle(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('holidays.manage'), 403);

        if (blank(config('services.google.calendar_api_key'))) {
            Toast::error(GoogleSyncError::NotConfigured->message());

            return back();
        }

        try {
            app(GoogleHolidaySync::class)->sync();
            Toast::success('Holidays imported from Google Calendar.');
        } catch (GoogleApiException $e) {
            Toast::error($e->error->message());
        }

        return back();
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

        // An edited import is the admin's now: later Google syncs leave it alone.
        $holiday->update($this->validated($request) + ['overridden' => $holiday->source === 'google']);

        Toast::success('Holiday updated.');

        return back();
    }

    public function destroy(Holiday $holiday): RedirectResponse
    {
        abort_unless(request()->user()->can('holidays.manage'), 403);

        // A deleted import is soft-deleted so the next Google sync doesn't bring it back.
        $holiday->source === 'google' ? $holiday->delete() : $holiday->forceDelete();

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
            'type' => ['required', Rule::in(['regular', 'special_non_working', 'company', 'observance'])],
            'blocks_bookings' => ['boolean'],
        ]);
    }
}
