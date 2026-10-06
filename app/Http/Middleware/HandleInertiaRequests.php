<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Settings\GoogleCalendarController;
use App\Models\Announcement;
use App\Models\ResourceType;
use App\Services\Booking\ReviewQueue;
use App\Settings\GeneralSettings;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        // Everything query-backed is wrapped in closures: Inertia resolves
        // them only when rendering a page, so plain /api/v1 JSON requests
        // skip the role/permission/announcement/resource-type queries.
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'organization' => fn () => [
                'name' => app(GeneralSettings::class)->organization_name,
                'acronym' => app(GeneralSettings::class)->organization_acronym,
            ],
            'auth' => fn () => [
                'user' => $user === null ? null : [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar_path' => $user->avatar_path,
                    'status' => $user->status->value,
                    'timezone' => $user->timezone,
                    // Their own names for colors; null until first edited.
                    'color_labels' => $user->color_labels,
                    'division' => $user->division === null ? null : [
                        'id' => $user->division->id,
                        'name' => $user->division->name,
                        'acronym' => $user->division->acronym,
                        'color' => $user->division->color,
                    ],
                    'roles' => $roles = $user->getRoleNames()->all(),
                    'permissions' => $user->getAllPermissions()->pluck('name')->all(),
                    'is_super_admin' => in_array('super_admin', $roles, true),
                ],
            ],
            'announcements' => fn () => $user === null || ! $user->isActive()
                ? []
                : Announcement::query()
                    ->visibleTo($user)
                    ->orderByDesc('level')
                    ->limit(3)
                    ->get(['id', 'title', 'body', 'level', 'is_dismissible'])
                    ->toArray(),
            // Each active resource type gets its own sidebar section
            // (Boardroom, Carpool, ...) with a calendar + booking page.
            'resourceSections' => fn () => $user === null || ! $user->isActive()
                ? []
                : ResourceType::query()
                    ->active()
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->get(['id', 'name', 'slug', 'icon'])
                    ->toArray(),
            // What waits on this reviewer, for the top bar's "to review"
            // control; null for everyone who reviews nothing.
            'reviewQueue' => fn () => $user === null || ! $user->isActive()
                ? null
                : app(ReviewQueue::class)->summary($user),
            // Whether the calendar panel offers "Connect Google Calendar".
            'googleCalendar' => fn () => $user === null ? null : [
                'configured' => GoogleCalendarController::configured(),
                'connected' => $user->googleAccount !== null,
                'needs_reconnect' => $user->googleAccount !== null && ! $user->googleAccount->isActive(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // The calendar side panel's dragged width, so the server paints
            // it at the user's size; null (default width) when unset or bad.
            'calendarPanelWidth' => filter_var($request->cookie('calendar_panel_width'), FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 200, 'max_range' => 400],
            ]) ?: null,
            // Dragged shut past its minimum; reopened from the edge tab.
            'calendarPanelHidden' => $request->cookie('calendar_panel_hidden') === '1',
            // Lets the theme toggle render its real state on first paint
            // (explicit light/dark); 'system' still resolves client-side.
            'appearance' => $request->cookie('appearance') ?? 'light',
        ];
    }
}
