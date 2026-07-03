<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DivisionController;
use App\Http\Controllers\Admin\EventTypeController;
use App\Http\Controllers\Admin\InvitationController;
use App\Http\Controllers\Admin\ManagedCalendarController;
use App\Http\Controllers\Admin\PendingRegistrationController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'verified', 'approved', 'can:admin.access'])
    ->group(function () {
        Route::get('/', DashboardController::class)->name('dashboard');

        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::patch('users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('users/{user}/suspend', [UserController::class, 'suspend'])->name('users.suspend');
        Route::post('users/{user}/reactivate', [UserController::class, 'reactivate'])->name('users.reactivate');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

        Route::get('pending-registrations', [PendingRegistrationController::class, 'index'])->name('pending-registrations.index');
        Route::post('pending-registrations/{user}/approve', [PendingRegistrationController::class, 'approve'])->name('pending-registrations.approve');
        Route::delete('pending-registrations/{user}', [PendingRegistrationController::class, 'reject'])->name('pending-registrations.reject');

        Route::get('invitations', [InvitationController::class, 'index'])->name('invitations.index');
        Route::post('invitations', [InvitationController::class, 'store'])->name('invitations.store');
        Route::post('invitations/{invitation}/resend', [InvitationController::class, 'resend'])->name('invitations.resend');
        Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])->name('invitations.destroy');

        Route::get('divisions', [DivisionController::class, 'index'])->name('divisions.index');
        Route::post('divisions', [DivisionController::class, 'store'])->name('divisions.store');
        Route::patch('divisions/{division}', [DivisionController::class, 'update'])->name('divisions.update');
        Route::delete('divisions/{division}', [DivisionController::class, 'destroy'])->name('divisions.destroy');

        Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
        Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
        Route::patch('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');

        Route::get('calendars', [ManagedCalendarController::class, 'index'])->name('calendars.index');
        Route::post('calendars', [ManagedCalendarController::class, 'store'])->name('calendars.store');
        Route::patch('calendars/{calendar}', [ManagedCalendarController::class, 'update'])->name('calendars.update');
        Route::delete('calendars/{calendar}', [ManagedCalendarController::class, 'destroy'])->name('calendars.destroy');

        Route::get('event-types', [EventTypeController::class, 'index'])->name('event-types.index');
        Route::post('event-types', [EventTypeController::class, 'store'])->name('event-types.store');
        Route::patch('event-types/{event_type}', [EventTypeController::class, 'update'])->name('event-types.update');
        Route::delete('event-types/{event_type}', [EventTypeController::class, 'destroy'])->name('event-types.destroy');
    });
