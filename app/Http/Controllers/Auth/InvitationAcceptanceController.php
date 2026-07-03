<?php

namespace App\Http\Controllers\Auth;

use App\Concerns\PasswordValidationRules;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
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
        $invitation = Invitation::query()->where('token', $token)->first();

        if ($invitation === null || $invitation->isAccepted() || $invitation->isExpired()) {
            return redirect()->route('login')->withErrors([
                'email' => 'This invitation link is no longer valid. Ask an administrator for a new one.',
            ]);
        }

        return Inertia::render('auth/AcceptInvitation', [
            'token' => $token,
            'email' => $invitation->email,
            'inviter' => $invitation->inviter->name,
        ]);
    }

    public function store(Request $request, string $token, InvitationService $invitations): RedirectResponse
    {
        $invitation = Invitation::query()->where('token', $token)->first();

        if ($invitation === null || $invitation->isAccepted() || $invitation->isExpired()) {
            return redirect()->route('login')->withErrors([
                'email' => 'This invitation link is no longer valid. Ask an administrator for a new one.',
            ]);
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
}
