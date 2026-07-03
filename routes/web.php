<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\InvitationAcceptanceController;
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
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
