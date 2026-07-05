<?php

namespace App\Http\Middleware;

use App\Models\Announcement;
use App\Models\ResourceType;
use App\Services\Chat\ConversationService;
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
            'auth' => fn () => [
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
            'resourceTypes' => fn () => $user === null || ! $user->isActive()
                ? []
                : ResourceType::query()
                    ->active()
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->get(['id', 'name', 'slug', 'icon'])
                    ->toArray(),
            // Chat conversation list for the sidebar badge and the dock:
            // division rooms + custom group chats the user belongs to.
            'chatConversations' => fn () => $user === null || ! $user->isActive()
                ? []
                : app(ConversationService::class)->summaries($user),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // Lets the theme toggle render its real state on first paint
            // (explicit light/dark); 'system' still resolves client-side.
            'appearance' => $request->cookie('appearance') ?? 'system',
        ];
    }
}
