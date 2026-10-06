<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Settings\GeneralSettings;
use App\Settings\RegistrationSettings;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/settings/Index');
    }

    public function edit(Request $request, GeneralSettings $general, RegistrationSettings $registration): Response
    {
        abort_unless($request->user()->can('settings.manage'), 403);

        return Inertia::render('admin/settings/General', [
            'general' => [
                'organization_name' => $general->organization_name,
                'organization_acronym' => $general->organization_acronym,
                'timezone' => $general->timezone,
                'week_starts_on' => $general->week_starts_on,
            ],
            'registration' => [
                'self_registration_enabled' => $registration->self_registration_enabled,
                'allowed_email_domain' => $registration->allowed_email_domain,
                'require_approval' => $registration->require_approval,
            ],
        ]);
    }

    public function update(Request $request, GeneralSettings $general, RegistrationSettings $registration): RedirectResponse
    {
        abort_unless($request->user()->can('settings.manage'), 403);

        $validated = $request->validate([
            'general.organization_name' => ['required', 'string', 'max:255'],
            'general.organization_acronym' => ['required', 'string', 'max:20'],
            'general.timezone' => ['required', 'timezone'],
            'general.week_starts_on' => ['required', Rule::in([0, 1])],
            'registration.self_registration_enabled' => ['required', 'boolean'],
            'registration.allowed_email_domain' => ['nullable', 'string', 'max:100'],
            'registration.require_approval' => ['required', 'boolean'],
        ]);

        $before = ['general' => $general->toArray(), 'registration' => $registration->toArray()];

        $general->fill($validated['general'])->save();
        $registration->fill($validated['registration'])->save();

        activity()
            ->causedBy($request->user())
            ->event('settings-updated')
            ->withProperties(['old' => $before, 'attributes' => $validated])
            ->log('Updated system settings');

        Toast::success('Settings saved.');

        return back();
    }
}
