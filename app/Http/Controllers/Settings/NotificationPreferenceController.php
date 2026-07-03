<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class NotificationPreferenceController extends Controller
{
    /**
     * Every notification key a user can tune, grouped for the settings UI.
     *
     * @var array<string, array<string, string>>
     */
    public const KEYS = [
        'Events' => [
            'event.invited' => 'Someone invites you to an event',
            'event.rsvp-changed' => 'An attendee responds to your invitation',
            'event.reminder' => 'Event reminders you set',
        ],
        'Bookings' => [
            'booking.requested' => 'A booking needs your approval',
            'booking.approved' => 'Your booking is approved',
            'booking.rejected' => 'Your booking is rejected',
            'booking.cancelled' => 'A booking is cancelled',
            'edit-request.submitted' => 'A booking change needs your review',
            'edit-request.applied' => 'Your booking change is applied',
            'edit-request.rejected' => 'Your booking change is rejected',
        ],
        'Account' => [
            'user.registered' => 'A new registration awaits approval',
            'user.approved' => 'Your account is approved',
        ],
    ];

    public function edit(Request $request): Response
    {
        $preferences = NotificationPreference::query()
            ->where('user_id', $request->user()->id)
            ->get()
            ->keyBy('notification_key');

        $groups = collect(self::KEYS)->map(
            fn (array $keys) => collect($keys)->map(fn (string $label, string $key) => [
                'key' => $key,
                'label' => $label,
                'mail_enabled' => $preferences->get($key)->mail_enabled ?? true,
                'database_enabled' => $preferences->get($key)->database_enabled ?? true,
            ])->values(),
        );

        return Inertia::render('settings/Notifications', [
            'groups' => $groups,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $allKeys = array_merge(...array_map('array_keys', array_values(self::KEYS)));

        $validated = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*.key' => ['required', 'string', Rule::in($allKeys)],
            'preferences.*.mail_enabled' => ['required', 'boolean'],
            'preferences.*.database_enabled' => ['required', 'boolean'],
        ]);

        foreach ($validated['preferences'] as $preference) {
            NotificationPreference::query()->updateOrCreate(
                [
                    'user_id' => $request->user()->id,
                    'notification_key' => $preference['key'],
                ],
                [
                    'mail_enabled' => $preference['mail_enabled'],
                    'database_enabled' => $preference['database_enabled'],
                ],
            );
        }

        Toast::success('Notification preferences saved.');

        return back();
    }
}
