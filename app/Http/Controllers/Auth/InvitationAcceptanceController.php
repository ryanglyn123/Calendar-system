<?php

namespace App\Http\Controllers\Auth;

use App\Concerns\PasswordValidationRules;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\User;
use App\Services\Identity\InvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class InvitationAcceptanceController extends Controller
{
    use PasswordValidationRules;

    public function show(string $token): Response|RedirectResponse
    {
        $invitation = $this->usable($token);

        if ($invitation === null) {
            return $this->invalid();
        }

        return Inertia::render('auth/AcceptInvitation', [
            'token' => $token,
            'email' => $invitation->email,
            'inviter' => $invitation->inviter->name,
        ]);
    }

    public function store(Request $request, string $token, InvitationService $invitations): RedirectResponse
    {
        $invitation = $this->usable($token);

        if ($invitation === null) {
            return $this->invalid();
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => $this->passwordRules(),
        ]);

        $user = $invitations->accept($invitation, $validated['name'], $validated['password']);

        Auth::login($user, remember: true);

        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    /**
     * The invitation behind the token, if it can still be accepted. An
     * account that already exists for the address (active or archived)
     * would collide with the users.email unique index.
     */
    private function usable(string $token): ?Invitation
    {
        $invitation = Invitation::query()->where('token', $token)->first();

        if ($invitation === null || $invitation->isAccepted() || $invitation->isExpired()) {
            return null;
        }

        if (User::withTrashed()->where('email', $invitation->email)->exists()) {
            return null;
        }

        return $invitation;
    }

    private function invalid(): RedirectResponse
    {
        return redirect()->route('login')->withErrors([
            'email' => 'This invitation link is no longer valid. Ask an administrator for a new one.',
        ]);
    }
}
