<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreInvitationRequest;
use App\Http\Resources\DivisionResource;
use App\Http\Resources\InvitationResource;
use App\Http\Resources\RoleResource;
use App\Models\Division;
use App\Models\Invitation;
use App\Models\Role;
use App\Services\Identity\InvitationService;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InvitationController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Invitation::class);

        return Inertia::render('admin/invitations/Index', [
            'invitations' => InvitationResource::collection(
                Invitation::query()
                    ->with(['division', 'role', 'inviter'])
                    ->whereNull('accepted_at')
                    ->latest()
                    ->paginate(15),
            ),
            'divisions' => DivisionResource::collection(Division::query()->active()->orderBy('sort_order')->get()),
            'roles' => RoleResource::collection(Role::query()->orderBy('name')->get()),
        ]);
    }

    public function store(StoreInvitationRequest $request, InvitationService $invitations): RedirectResponse
    {
        $role = $request->validated('role');

        $invitations->issue(
            $request->validated('email'),
            $request->validated('division_id'),
            $role !== null ? Role::query()->where('name', $role)->value('id') : null,
            $request->user(),
        );

        Toast::success("Invitation sent to {$request->validated('email')}.");

        return back();
    }

    public function resend(Request $request, Invitation $invitation, InvitationService $invitations): RedirectResponse
    {
        $this->authorize('create', Invitation::class);

        $invitations->resend($invitation, $request->user());

        Toast::success("Invitation re-sent to {$invitation->email}.");

        return back();
    }

    public function destroy(Invitation $invitation): RedirectResponse
    {
        $this->authorize('delete', $invitation);
        abort_if($invitation->isAccepted(), 403, 'Accepted invitations cannot be revoked.');

        $invitation->delete();

        Toast::success('Invitation revoked.');

        return back();
    }
}
