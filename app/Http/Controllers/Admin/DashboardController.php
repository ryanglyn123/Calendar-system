<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ResourceType;
use App\Models\User;
use App\Services\Admin\AdminDashboard;
use App\Services\Booking\ReviewQueue;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The admin desk, one per resource type: Boardroom and Carpool reviewers
 * each get only their own; super admins switch between them (`type`).
 * `date` moves the bookings list; the page reloads just those props.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, AdminDashboard $dashboard, ReviewQueue $reviews): Response
    {
        $validated = $request->validate([
            'type' => ['nullable', 'string', 'max:100'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $user = $request->user();
        $types = $reviews->typesFor($user);

        /** @var ResourceType|null $type */
        $type = isset($validated['type'])
            ? $types->firstWhere('slug', $validated['type'])
            : $types->first();

        // Someone else's desk, or a type that doesn't exist.
        abort_if(isset($validated['type']) && $type === null, 403);

        $today = CarbonImmutable::now(AdminDashboard::timezone())->startOfDay();
        $day = isset($validated['date'])
            ? CarbonImmutable::parse($validated['date'], AdminDashboard::timezone())->startOfDay()
            : $today;

        return Inertia::render('admin/Dashboard', [
            'today' => $today->toDateString(),
            'date' => $day->toDateString(),
            'tabs' => fn () => $dashboard->tabs($types),
            'type' => $type === null ? null : $type->only(['slug', 'name', 'icon']),
            'requests' => fn () => $type === null ? [] : $dashboard->requests($user, $type),
            'resources' => fn () => $type === null ? [] : ($dashboard->schedule($user, $day, $type)['types'][0]['resources'] ?? []),
            'week' => fn () => $type === null ? [] : $dashboard->week($type, $day->startOfWeek(CarbonInterface::MONDAY)),
            'signups' => fn () => $user->can('viewAny', User::class) ? User::query()->pending()->count() : null,
        ]);
    }
}
