<?php

use App\Http\Controllers\Admin\BookingApprovalController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DivisionController;
use App\Http\Controllers\Admin\EditRequestController;
use App\Http\Controllers\Admin\EventTypeController;
use App\Http\Controllers\Admin\HolidayController;
use App\Http\Controllers\Admin\InvitationController;
use App\Http\Controllers\Admin\ManagedCalendarController;
use App\Http\Controllers\Admin\PendingRegistrationController;
use App\Http\Controllers\Admin\ResourceController;
use App\Http\Controllers\Admin\ResourceTypeController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WorkingHourController;
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

        Route::get('resource-types', [ResourceTypeController::class, 'index'])->name('resource-types.index');
        Route::post('resource-types', [ResourceTypeController::class, 'store'])->name('resource-types.store');
        Route::patch('resource-types/{resource_type}', [ResourceTypeController::class, 'update'])->name('resource-types.update');
        Route::delete('resource-types/{resource_type}', [ResourceTypeController::class, 'destroy'])->name('resource-types.destroy');

        Route::get('resources', [ResourceController::class, 'index'])->name('resources.index');
        Route::post('resources', [ResourceController::class, 'store'])->name('resources.store');
        Route::patch('resources/{resource}', [ResourceController::class, 'update'])->name('resources.update');
        Route::delete('resources/{resource}', [ResourceController::class, 'destroy'])->name('resources.destroy');

        Route::get('bookings', [BookingApprovalController::class, 'index'])->name('bookings.index');
        Route::post('bookings/{booking}/approve', [BookingApprovalController::class, 'approve'])
            ->middleware('throttle:booking-actions')->name('bookings.approve');
        Route::post('bookings/{booking}/reject', [BookingApprovalController::class, 'reject'])
            ->middleware('throttle:booking-actions')->name('bookings.reject');

        Route::get('edit-requests', [EditRequestController::class, 'index'])->name('edit-requests.index');
        Route::post('edit-requests/{edit_request}/apply', [EditRequestController::class, 'apply'])
            ->middleware('throttle:booking-actions')->name('edit-requests.apply');
        Route::post('edit-requests/{edit_request}/reject', [EditRequestController::class, 'reject'])
            ->middleware('throttle:booking-actions')->name('edit-requests.reject');

        Route::get('holidays', [HolidayController::class, 'index'])->name('holidays.index');
        Route::post('holidays', [HolidayController::class, 'store'])->name('holidays.store');
        Route::patch('holidays/{holiday}', [HolidayController::class, 'update'])->name('holidays.update');
        Route::delete('holidays/{holiday}', [HolidayController::class, 'destroy'])->name('holidays.destroy');

        Route::get('working-hours', [WorkingHourController::class, 'index'])->name('working-hours.index');
        Route::put('working-hours', [WorkingHourController::class, 'update'])->name('working-hours.update');
    });
