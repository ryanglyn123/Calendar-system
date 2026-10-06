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
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InvitationController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Invitation::class);

        $status = $request->validate([
            'status' => ['nullable', Rule::in(['waiting', 'expired'])],
        ])['status'] ?? null;

        $pending = fn () => Invitation::query()->whereNull('accepted_at');
        $waiting = fn ($q) => $q->where('expires_at', '>=', now());
        $expired = fn ($q) => $q->where('expires_at', '<', now());

        return Inertia::render('admin/invitations/Index', [
            'invitations' => InvitationResource::collection(
                $pending()
                    ->with(['division', 'role', 'inviter'])
                    ->when($status === 'waiting', $waiting)
                    ->when($status === 'expired', $expired)
                    ->latest()
                    ->paginate(15)
                    ->withQueryString(),
            ),
            'counts' => [
                'all' => $pending()->count(),
                'waiting' => $pending()->where($waiting)->count(),
                'expired' => $pending()->where($expired)->count(),
            ],
            'divisions' => DivisionResource::collection(Division::query()->active()->orderBy('sort_order')->get()),
            'roles' => RoleResource::collection(Role::query()->orderBy('name')->get()),
            'filters' => $request->only(['status']),
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
