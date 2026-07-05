<?php

namespace App\Http\Controllers\Admin;

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
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()
            ->with(['division', 'roles'])
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request) {
                $search = $request->string('search');
                $query->where(fn ($q) => $q
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%"));
            })
            ->when($request->filled('division'), fn ($query) => $query->where('division_id', $request->integer('division')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('admin/users/Index', [
            'users' => UserResource::collection($users),
            'divisions' => DivisionResource::collection(Division::query()->active()->orderBy('sort_order')->get()),
            'roles' => RoleResource::collection(Role::query()->orderBy('name')->get()),
            'filters' => $request->only(['search', 'division', 'status']),
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
