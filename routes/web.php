<?php

use App\Http\Controllers\Admin\EventTypeController;
use App\Http\Controllers\Api\AnnouncementDismissalController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\CalendarListController;
use App\Http\Controllers\Api\CalendarPreferenceController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\EventCommentController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\EventOccurrenceController;
use App\Http\Controllers\Api\EventRsvpController;
use App\Http\Controllers\Api\EventWindowController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ResourceBrowserController;
use App\Http\Controllers\Api\UserSearchController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\InvitationAcceptanceController;
use App\Http\Controllers\Calendar\CalendarShareController;
use App\Http\Controllers\Calendar\DivisionUnitController;
use App\Http\Controllers\Calendar\PersonalCalendarController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DivisionCalendarController;
use App\Http\Controllers\DivisionChatController;
use App\Http\Controllers\PublicCalendarController;
use App\Http\Controllers\ResourceSectionController;
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
    Route::get('calendar/public', PublicCalendarController::class)->name('calendar.public');
    Route::get('calendar/division', DivisionCalendarController::class)->name('calendar.division');
    Route::get('chat', DivisionChatController::class)->name('chat.index');

    // One section page per active resource type (Boardroom, Carpool, ...):
    // the type's calendar plus booking, linked from the sidebar.
    Route::get('resources/{resourceType:slug}', ResourceSectionController::class)->name('resources.section');

    // Personal calendar management + person-to-person sharing (Inertia forms).
    Route::post('calendars', [PersonalCalendarController::class, 'store'])->name('calendars.store');
    Route::patch('calendars/{calendar}', [PersonalCalendarController::class, 'update'])->name('calendars.update');
    Route::delete('calendars/{calendar}', [PersonalCalendarController::class, 'destroy'])->name('calendars.destroy');
    Route::post('calendars/{calendar}/shares', [CalendarShareController::class, 'store'])->name('calendars.shares.store');
    Route::delete('calendars/{calendar}/shares/{share}', [CalendarShareController::class, 'destroy'])->name('calendars.shares.destroy');

    // Units: member-managed sub-divisions of their own division.
    Route::post('division-units', [DivisionUnitController::class, 'store'])->name('division-units.store');
    Route::patch('division-units/{division}', [DivisionUnitController::class, 'update'])->name('division-units.update');
    Route::delete('division-units/{division}', [DivisionUnitController::class, 'destroy'])->name('division-units.destroy');

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
        Route::put('events/{event}/rsvp', [EventRsvpController::class, 'update'])->name('events.rsvp');

        // Event discussion — open to anyone who can view the event's calendar.
        Route::get('events/{event}/comments', [EventCommentController::class, 'index'])->name('events.comments.index');
        Route::post('events/{event}/comments', [EventCommentController::class, 'store'])
            ->middleware('throttle:chat-send')->name('events.comments.store');
        Route::delete('events/comments/{comment}', [EventCommentController::class, 'destroy'])->name('events.comments.destroy');

        // Inline event-type creation from the event editor (admins only; gated in the request).
        Route::post('event-types', [EventTypeController::class, 'store'])->name('event-types.store');

        Route::get('users/search', UserSearchController::class)->name('users.search');

        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
        Route::post('notifications/{id}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
        Route::delete('notifications', [NotificationController::class, 'destroyAll'])->name('notifications.clear');

        Route::get('resources', [ResourceBrowserController::class, 'index'])->name('resources.index');
        Route::get('resources/{resource}/availability', [ResourceBrowserController::class, 'availability'])->name('resources.availability');

        Route::post('bookings', [BookingController::class, 'store'])
            ->middleware('throttle:booking-actions')->name('bookings.store');
        Route::get('bookings/mine', [BookingController::class, 'mine'])->name('bookings.mine');
        Route::post('bookings/{booking}/cancel', [BookingController::class, 'cancel'])
            ->middleware('throttle:booking-actions')->name('bookings.cancel');
        Route::post('bookings/{booking}/edit-requests', [BookingController::class, 'requestEdit'])
            ->middleware('throttle:booking-actions')->name('bookings.edit-requests.store');

        Route::post('announcements/{announcement}/dismiss', [AnnouncementDismissalController::class, 'store'])->name('announcements.dismiss');

        // Chat conversations: division rooms + custom group chats. The
        // conversation list, plus custom-chat creation and management.
        Route::get('conversations', [ConversationController::class, 'index'])->name('conversations.index');
        Route::get('conversations/candidates', [ConversationController::class, 'participantCandidates'])->name('conversations.candidates');
        Route::post('conversations', [ConversationController::class, 'store'])
            ->middleware('throttle:chat-send')->name('conversations.store');
        Route::patch('conversations/{conversation}', [ConversationController::class, 'update'])
            ->middleware('throttle:chat-send')->name('conversations.update');
        Route::post('conversations/{conversation}/leave', [ConversationController::class, 'leave'])
            ->middleware('throttle:chat-send')->name('conversations.leave');

        // Per-conversation reads/writes. Reads share the group throttle;
        // writes get their own limiters, media streaming a generous one.
        Route::get('conversations/{conversation}/messages', [ChatController::class, 'index'])->name('chat.messages.index');
        Route::post('conversations/{conversation}/messages', [ChatController::class, 'store'])
            ->middleware('throttle:chat-send')->name('chat.messages.store');
        Route::get('conversations/{conversation}/members', [ChatController::class, 'members'])->name('chat.members');
        Route::get('conversations/{conversation}/pinned', [ChatController::class, 'pinned'])->name('chat.pinned');
        Route::post('conversations/{conversation}/read', [ChatController::class, 'markRead'])
            ->middleware('throttle:chat-react')->name('chat.read');
        Route::post('conversations/{conversation}/photo', [ChatController::class, 'updatePhoto'])
            ->middleware('throttle:chat-send')->name('chat.photo.update');
        Route::get('conversations/{conversation}/photo', [ChatController::class, 'showPhoto'])
            ->middleware('throttle:chat-media')->name('chat.photo.show');

        // Message-level actions, bound directly to the message/poll/attachment.
        Route::delete('chat/messages/{message}', [ChatController::class, 'destroy'])
            ->middleware('throttle:chat-send')->name('chat.messages.destroy');
        Route::post('chat/messages/{message}/reactions', [ChatController::class, 'toggleReaction'])
            ->middleware('throttle:chat-react')->name('chat.reactions.toggle');
        Route::post('chat/messages/{message}/pin', [ChatController::class, 'togglePin'])
            ->middleware('throttle:chat-react')->name('chat.pin.toggle');
        Route::put('chat/polls/{poll}/votes', [ChatController::class, 'vote'])
            ->middleware('throttle:chat-react')->name('chat.polls.votes');
        Route::get('chat/attachments/{attachment}', [ChatController::class, 'showAttachment'])
            ->middleware('throttle:chat-media')->name('chat.attachments.show');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
