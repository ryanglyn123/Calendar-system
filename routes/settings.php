<?php

use App\Http\Controllers\Settings\GoogleCalendarController;
use App\Http\Controllers\Settings\NotificationPreferenceController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'approved'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified', 'approved'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');

    Route::get('settings/notifications', [NotificationPreferenceController::class, 'edit'])->name('notifications.edit');
    Route::put('settings/notifications', [NotificationPreferenceController::class, 'update'])->name('notifications.update');

    Route::get('settings/google-calendar', [GoogleCalendarController::class, 'edit'])->name('google-calendar.edit');
    Route::patch('settings/google-calendar', [GoogleCalendarController::class, 'update'])->name('google-calendar.update');
    Route::delete('settings/google-calendar', [GoogleCalendarController::class, 'destroy'])->name('google-calendar.destroy');
    Route::get('settings/google-calendar/connect', [GoogleCalendarController::class, 'redirect'])
        ->middleware('throttle:10,1')->name('google-calendar.connect');
    Route::get('settings/google-calendar/callback', [GoogleCalendarController::class, 'callback'])->name('google-calendar.callback');
    Route::post('settings/google-calendar/sync', [GoogleCalendarController::class, 'sync'])
        ->middleware('throttle:6,1')->name('google-calendar.sync');
    Route::patch('settings/google-calendar/calendars/{calendar}', [GoogleCalendarController::class, 'updateCalendar'])
        ->whereNumber('calendar')->name('google-calendar.calendars.update');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
