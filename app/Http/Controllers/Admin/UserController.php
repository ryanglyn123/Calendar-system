<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\DivisionResource;
use App\Http\Resources\RoleResource;
use App\Http\Resources\UserResource;
use App\Models\Division;
use App\Models\Role;
use App\Models\User;
use App\Services\Calendar\CalendarAccessService;
use App\Services\Identity\UserAccountService;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'division' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(UserStatus::class)],
            'role' => ['nullable', 'string', 'exists:roles,name'],
            'sort' => ['nullable', Rule::in(['name', 'newest', 'division'])],
        ]);

        $users = User::query()
            ->with(['division', 'roles'])
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->when($filters['division'] ?? null, fn ($query, $division) => $query->where('division_id', $division))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['role'] ?? null, fn ($query, $role) => $query->whereHas('roles', fn ($q) => $q->where('name', $role)))
            ->when($filters['sort'] ?? 'name', fn ($query, $sort) => match ($sort) {
                'newest' => $query->latest()->orderBy('name'),
                // People without a division sort last.
                'division' => $query->orderByRaw('division_id is null')
                    ->orderBy(Division::query()->select('name')->whereColumn('divisions.id', 'users.division_id'))
                    ->orderBy('name'),
                default => $query->orderBy('name'),
            })
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('admin/users/Index', [
            'users' => UserResource::collection($users),
            'divisions' => DivisionResource::collection(Division::query()->active()->orderBy('sort_order')->get()),
            'roles' => RoleResource::collection(Role::query()->orderBy('name')->get()),
            'filters' => $request->only(['search', 'division', 'status', 'role', 'sort']),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user, CalendarAccessService $access): RedirectResponse
    {
        $user->update([
            'name' => $request->validated('name'),
            'division_id' => $request->validated('division_id'),
        ]);

        // A division move changes which calendars the user may see; the
        // accessible set is cached for five minutes otherwise.
        if ($user->wasChanged('division_id')) {
            $access->forgetFor($user);
        }

        $user->syncRoles($request->validated('roles'));

        activity()
            ->causedBy($request->user())
            ->performedOn($user)
            ->event('updated')
            ->withProperties(['roles' => $request->validated('roles'), 'division_id' => $request->validated('division_id')])
            ->log("Updated {$user->email}");

        Toast::success("{$user->name} updated.");

        return back();
    }

    public function suspend(Request $request, User $user, UserAccountService $accounts): RedirectResponse
    {
        $this->authorize('suspend', $user);
        $this->guardProtectedAccount($request, $user);

        $accounts->suspend($user, $request->user());

        Toast::success("{$user->name} suspended.");

        return back();
    }

    public function reactivate(Request $request, User $user, UserAccountService $accounts): RedirectResponse
    {
        $this->authorize('suspend', $user);

        $accounts->reactivate($user, $request->user());

        Toast::success("{$user->name} reactivated.");

        return back();
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->authorize('delete', $user);
        $this->guardProtectedAccount($request, $user);

        $user->delete();

        Toast::success("{$user->name} removed.");

        return back();
    }

    /**
     * Invariants that hold for every actor, super admins included: nobody
     * suspends or deletes their own account or a super admin's.
     */
    private function guardProtectedAccount(Request $request, User $target): void
    {
        abort_if($request->user()->is($target), 403, 'You cannot do this to your own account.');
        abort_if($target->hasRole('super_admin'), 403, 'Super admin accounts are protected.');
    }
}
