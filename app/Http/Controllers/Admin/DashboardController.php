<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Admin\AdminDashboard;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The admin desk: what waits on this user, how the rooms and vehicles are
 * used, and the few signals worth watching. Each panel appears only for
 * people allowed to act on it. `date` moves the schedule board; the page
 * reloads just that prop when it changes.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, AdminDashboard $dashboard): Response
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $user = $request->user();
        $today = CarbonImmutable::now(AdminDashboard::timezone())->startOfDay();
        $day = isset($validated['date'])
            ? CarbonImmutable::parse($validated['date'], AdminDashboard::timezone())->startOfDay()
            : $today;

        return Inertia::render('admin/Dashboard', [
            'today' => $today->toDateString(),
            'queue' => fn () => $dashboard->queue($user),
            'schedule' => fn () => $dashboard->schedule($user, $day),
            'demand' => fn () => $dashboard->demand(),
            'people' => fn () => $user->can('viewAny', User::class) ? $dashboard->people() : null,
            'notices' => fn () => $user->can('announcements.manage') ? $dashboard->announcements() : null,
            'activity' => fn () => $user->can('audit.view') ? $dashboard->activity() : null,
            'health' => fn () => $user->can('settings.manage') ? $dashboard->health() : null,
        ]);
    }
}
