<?php

use App\Enums\RsvpStatus;
use App\Models\Calendar;
use App\Models\Event;
use App\Models\EventComment;
use App\Models\User;
use App\Notifications\EventCommentedNotification;
use Illuminate\Support\Facades\Notification;

test('anyone who can view the event can list and post comments', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->organization()->create();
    $event = Event::factory()->create(['calendar_id' => $calendar->id]);

    $this->actingAs($user)
        ->postJson(route('api.events.comments.store', $event), ['body' => 'See you there'])
        ->assertCreated()
        ->assertJsonPath('data.body', 'See you there')
        ->assertJsonPath('data.user.id', $user->id);

    $this->actingAs($user)
        ->getJson(route('api.events.comments.index', $event))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('users without access to the calendar get 403', function () {
    $owner = User::factory()->create();
    $personal = Calendar::factory()->ownedBy($owner)->create();
    $event = Event::factory()->create(['calendar_id' => $personal->id]);
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->getJson(route('api.events.comments.index', $event))
        ->assertForbidden();

    $this->actingAs($outsider)
        ->postJson(route('api.events.comments.store', $event), ['body' => 'hi'])
        ->assertForbidden();
});

test('a new comment notifies the organizer and registered attendees, not the commenter', function () {
    Notification::fake();

    $organizer = User::factory()->create();
    $attendee = User::factory()->create();
    $commenter = User::factory()->create();
    $calendar = Calendar::factory()->organization()->create();
    $event = Event::factory()->create([
        'calendar_id' => $calendar->id,
        'created_by' => $organizer->id,
    ]);
    $event->attendees()->create([
        'user_id' => $attendee->id,
        'rsvp_status' => RsvpStatus::Pending,
    ]);

    $this->actingAs($commenter)
        ->postJson(route('api.events.comments.store', $event), ['body' => 'Agenda attached'])
        ->assertCreated();

    Notification::assertSentTo([$organizer, $attendee], EventCommentedNotification::class);
    Notification::assertNotSentTo($commenter, EventCommentedNotification::class);
});

test('only the author can delete a comment', function () {
    $author = User::factory()->create();
    $other = User::factory()->create();
    $calendar = Calendar::factory()->organization()->create();
    $event = Event::factory()->create(['calendar_id' => $calendar->id]);
    $comment = EventComment::query()->create([
        'event_id' => $event->id,
        'user_id' => $author->id,
        'body' => 'typo',
    ]);

    $this->actingAs($other)
        ->deleteJson(route('api.events.comments.destroy', $comment))
        ->assertForbidden();

    $this->actingAs($author)
        ->deleteJson(route('api.events.comments.destroy', $comment))
        ->assertOk();

    expect(EventComment::query()->count())->toBe(0);
});

test('an empty or oversized body is rejected', function () {
    $user = User::factory()->create();
    $calendar = Calendar::factory()->organization()->create();
    $event = Event::factory()->create(['calendar_id' => $calendar->id]);

    $this->actingAs($user)
        ->postJson(route('api.events.comments.store', $event), ['body' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('body');

    $this->actingAs($user)
        ->postJson(route('api.events.comments.store', $event), ['body' => str_repeat('a', 2001)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('body');
});
