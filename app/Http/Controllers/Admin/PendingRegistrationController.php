<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ApproveUserRequest;
use App\Http\Resources\DivisionResource;
use App\Http\Resources\RoleResource;
use App\Http\Resources\UserResource;
use App\Models\Division;
use App\Models\Role;
use App\Models\User;
use App\Services\Identity\UserAccountService;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PendingRegistrationController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', User::class);

        return Inertia::render('admin/users/Pending', [
            'pending' => UserResource::collection(
                User::query()->pending()->orderBy('created_at')->get(),
            ),
            'divisions' => DivisionResource::collection(Division::query()->active()->orderBy('sort_order')->get()),
            'roles' => RoleResource::collection(Role::query()->orderBy('name')->get()),
        ]);
    }

    public function approve(ApproveUserRequest $request, User $user, UserAccountService $accounts): RedirectResponse
    {
        $accounts->approve(
            $user,
            $request->validated('division_id'),
            $request->validated('role'),
            $request->user(),
        );

        Toast::success("{$user->name} approved.");

        return back();
    }

    public function reject(Request $request, User $user, UserAccountService $accounts): RedirectResponse
    {
        $this->authorize('approve', $user);
        abort_unless($user->isPending(), 403, 'Only pending registrations can be rejected.');

        $accounts->reject($user, $request->user());

        Toast::success('Registration rejected.');

        return back();
    }
}
