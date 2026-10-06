<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\GoogleCalendarController;
use App\Models\GoogleAccount;
use App\Models\GoogleBookingEvent;
use App\Models\GoogleCalendar;
use App\Services\Google\GoogleChannels;
use App\Services\Google\GoogleHolidaySync;
use App\Settings\GoogleCalendarSettings;
use App\Support\Toast;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SystemHealthController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('settings.manage'), 403);

        return Inertia::render('admin/health/Index', [
            'queuedJobs' => DB::table('jobs')->count(),
            'failedJobs' => DB::table('failed_jobs')
                ->latest('failed_at')
                ->limit(20)
                ->get(['id', 'queue', 'exception', 'failed_at'])
                ->map(fn ($job) => [
                    'id' => $job->id,
                    'queue' => $job->queue,
                    'failed_at' => $job->failed_at,
                    'summary' => str($job->exception)->before("\n")->limit(160)->toString(),
                ]),
            'google' => $this->googleHealth(),
        ]);
    }

    /**
     * Integration health in aggregate. Admins see counts and states, never
     * anyone's calendar names or events.
     *
     * @return array<string, mixed>
     */
    private function googleHealth(): array
    {
        $calendars = GoogleCalendar::query()->where(fn ($q) => $q->where('sync_enabled', true)->orWhere('is_bookings', true));
        $settings = app(GoogleCalendarSettings::class);

        return [
            'oauth_configured' => GoogleCalendarController::configured(),
            'api_key_configured' => filled(config('services.google.calendar_api_key')),
            'push_available' => GoogleChannels::available(),
            'webhook_url' => config('services.google.calendar_webhook'),
            'redirect_url' => url()->to(config()->string('services.google.calendar_redirect')),
            'accounts' => GoogleAccount::query()->count(),
            'accounts_needing_reconnect' => GoogleAccount::query()->where('status', GoogleAccount::STATUS_REAUTH)->count(),
            'calendars_synced' => (clone $calendars)->count(),
            'calendars_with_errors' => (clone $calendars)->where('sync_state', 'error')->count(),
            'channels_active' => (clone $calendars)->where('channel_expires_at', '>', now())->count(),
            'last_sync_at' => (clone $calendars)->max('last_synced_at'),
            'booking_copies_failed' => GoogleBookingEvent::query()->where('state', GoogleBookingEvent::FAILED)->count(),
            'holidays_synced_at' => $settings->holidays_synced_at,
            'holidays_error' => GoogleHolidaySync::lastError(),
        ];
    }

    public function retry(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('settings.manage'), 403);

        $failer = app('queue.failer');
        $retried = 0;
        $dropped = 0;

        // One at a time: queue:retry rebuilds each job, and a job about a
        // booking or request that has since been deleted can never run.
        // Retrying "all" stops at the first such job with a 404.
        foreach ($failer->ids() as $id) {
            try {
                Artisan::call('queue:retry', ['id' => [$id]]);
                $retried++;
            } catch (ModelNotFoundException) {
                $failer->forget($id);
                $dropped++;
            }
        }

        match (true) {
            $retried + $dropped === 0 => Toast::success('No failed jobs to retry.'),
            $dropped === 0 => Toast::success("{$retried} failed ".str('job')->plural($retried).' queued for retry.'),
            default => Toast::success(
                "{$retried} failed ".str('job')->plural($retried).' queued for retry. '
                ."{$dropped} removed: the booking or request ".($dropped === 1 ? 'it was' : 'they were').' about no longer exists.'
            ),
        };

        return back();
    }
}
