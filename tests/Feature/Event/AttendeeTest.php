<?php

use App\Enums\RsvpStatus;
use App\Models\Calendar;
use App\Models\Event;
use App\Models\User;
use App\Notifications\EventInvitedNotification;
use App\Notifications\EventRsvpChangedNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->organizer = User::factory()->create();
    $this->calendar = Calendar::factory()->ownedBy($this->organizer)->create();
});

test('creating an event with attendees invites and notifies them', function () {
    Notification::fake();

    $guestA = User::factory()->create();
    $guestB = User::factory()->create();

    $this->actingAs($this->organizer)->postJson(route('api.events.store'), [
        'calendar_id' => $this->calendar->id,
        'title' => 'Kickoff',
        'starts_at' => '2026-08-03T09:00:00+08:00',
        'ends_at' => '2026-08-03T10:00:00+08:00',
        'attendees' => [$guestA->id, $guestB->id, $this->organizer->id],
    ])->assertCreated();

    $event = Event::query()->latest('id')->firstOrFail();

    // Organizer never becomes their own attendee row.
    expect($event->attendees()->count())->toBe(2);

    Notification::assertSentTo($guestA, EventInvitedNotification::class);
    Notification::assertSentTo($guestB, EventInvitedNotification::class);
    Notification::assertNotSentTo($this->organizer, EventInvitedNotification::class);
});

test('re-syncing attendees only notifies the newly added', function () {
    Notification::fake();

    $kept = User::factory()->create();
    $removed = User::factory()->create();
    $added = User::factory()->create();

    $event = Event::factory()->create([
        'calendar_id' => $this->calendar->id,
        'created_by' => $this->organizer->id,
    ]);

    $event->attendees()->create(['user_id' => $kept->id]);
    $event->attendees()->create(['user_id' => $removed->id]);

    $this->actingAs($this->organizer)->patchJson(route('api.events.update', $event), [
        'attendees' => [$kept->id, $added->id],
    ])->assertOk();

    expect($event->attendees()->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$kept->id, $added->id])->sort()->values()->all());

    Notification::assertSentTo($added, EventInvitedNotification::class);
    Notification::assertNotSentTo($kept, EventInvitedNotification::class);
});

test('invited users can rsvp and the organizer is notified', function () {
    Notification::fake();

    $guest = User::factory()->create();

    $event = Event::factory()->create([
        'calendar_id' => $this->calendar->id,
        'created_by' => $this->organizer->id,
    ]);

    $event->attendees()->create(['user_id' => $guest->id]);

    $this->actingAs($guest)->putJson(route('api.events.rsvp', $event), [
        'status' => 'accepted',
    ])->assertOk()->assertJsonPath('status', 'accepted');

    expect($event->attendees()->where('user_id', $guest->id)->value('rsvp_status'))
        ->toBe(RsvpStatus::Accepted);

    Notification::assertSentTo($this->organizer, EventRsvpChangedNotification::class);
});

test('users who are not invited cannot rsvp', function () {
    $stranger = User::factory()->create();

    $event = Event::factory()->create([
        'calendar_id' => $this->calendar->id,
        'created_by' => $this->organizer->id,
    ]);

    $this->actingAs($stranger)->putJson(route('api.events.rsvp', $event), [
        'status' => 'accepted',
    ])->assertForbidden();
});

test('the user search excludes self, pending and deleted users', function () {
    $me = User::factory()->create(['name' => 'Searcher Person']);
    $match = User::factory()->create(['name' => 'Teammate Match']);
    $pending = User::factory()->pending()->create(['name' => 'Pending Match']);

    $results = collect($this->actingAs($me)->getJson(route('api.users.search', ['q' => 'Match']))
        ->assertOk()
        ->json('data'));

    expect($results->pluck('id'))->toContain($match->id)
        ->and($results->pluck('id'))->not->toContain($pending->id)
        ->and($results->pluck('id'))->not->toContain($me->id);
});
