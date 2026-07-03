<?php

use App\Http\Controllers\Api\CalendarListController;
use App\Http\Controllers\Api\CalendarPreferenceController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\EventOccurrenceController;
use App\Http\Controllers\Api\EventWindowController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\InvitationAcceptanceController;
use App\Http\Controllers\Calendar\CalendarShareController;
use App\Http\Controllers\Calendar\PersonalCalendarController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware('guest')->group(function () {
    Route::get('auth/google/redirect', [GoogleAuthController::class, 'redirect'])->name('google.redirect');
    Route::get('auth/google/callback', [GoogleAuthController::class, 'callback'])->name('google.callback');

    Route::get('invitation/{token}', [InvitationAcceptanceController::class, 'show'])->name('invitation.show');
    Route::post('invitation/{token}', [InvitationAcceptanceController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('invitation.store');
});

Route::middleware('auth')->group(function () {
    Route::get('pending-approval', fn () => request()->user()->isActive()
        ? redirect()->route('dashboard')
        : Inertia::render('auth/PendingApproval', [
            'status' => request()->user()->status->value,
        ]))->name('approval.pending');
});

Route::middleware(['auth', 'verified', 'approved'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // Personal calendar management + person-to-person sharing (Inertia forms).
    Route::post('calendars', [PersonalCalendarController::class, 'store'])->name('calendars.store');
    Route::patch('calendars/{calendar}', [PersonalCalendarController::class, 'update'])->name('calendars.update');
    Route::delete('calendars/{calendar}', [PersonalCalendarController::class, 'destroy'])->name('calendars.destroy');
    Route::post('calendars/{calendar}/shares', [CalendarShareController::class, 'store'])->name('calendars.shares.store');
    Route::delete('calendars/{calendar}/shares/{share}', [CalendarShareController::class, 'destroy'])->name('calendars.shares.destroy');

    // Session JSON endpoints for the calendar UI. Registered inside the web
    // group on purpose: CSRF stays enforced, the fetch client forwards the
    // XSRF cookie.
    Route::prefix('api/v1')->name('api.')->middleware('throttle:calendar-data')->group(function () {
        Route::get('calendars', CalendarListController::class)->name('calendars.index');
        Route::put('calendars/{calendar}/preference', [CalendarPreferenceController::class, 'update'])->name('calendars.preference');

        Route::get('events', EventWindowController::class)->name('events.index');
        Route::post('events', [EventController::class, 'store'])->name('events.store');
        Route::patch('events/{event}', [EventController::class, 'update'])->name('events.update');
        Route::delete('events/{event}', [EventController::class, 'destroy'])->name('events.destroy');
        Route::patch('events/{event}/occurrence', [EventOccurrenceController::class, 'update'])->name('events.occurrence.update');
        Route::delete('events/{event}/occurrence', [EventOccurrenceController::class, 'destroy'])->name('events.occurrence.destroy');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
