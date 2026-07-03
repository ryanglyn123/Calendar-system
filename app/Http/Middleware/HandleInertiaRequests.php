<?php

namespace App\Http\Middleware;

use App\Models\Announcement;
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

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user === null ? null : [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar_path' => $user->avatar_path,
                    'status' => $user->status->value,
                    'timezone' => $user->timezone,
                    'division' => $user->division === null ? null : [
                        'id' => $user->division->id,
                        'name' => $user->division->name,
                        'acronym' => $user->division->acronym,
                        'color' => $user->division->color,
                    ],
                    'roles' => $user->getRoleNames()->all(),
                    'permissions' => $user->getAllPermissions()->pluck('name')->all(),
                    'is_super_admin' => $user->hasRole('super_admin'),
                ],
            ],
            'announcements' => $user === null || ! $user->isActive()
                ? []
                : Announcement::query()
                    ->visibleTo($user)
                    ->orderByDesc('level')
                    ->limit(3)
                    ->get(['id', 'title', 'body', 'level', 'is_dismissible'])
                    ->toArray(),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
