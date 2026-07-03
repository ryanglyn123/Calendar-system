<?php

use App\Jobs\SendDueReminders;
use App\Models\Calendar;
use App\Models\Event;
use App\Models\User;
use App\Notifications\EventReminderNotification;
use App\Notifications\UserApprovedNotification;
use App\Services\Event\OccurrenceExpander;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->calendar = Calendar::factory()->ownedBy($this->user)->create();
});

test('saving reminders stores one row per channel and lead time', function () {
    $this->actingAs($this->user)->postJson(route('api.events.store'), [
        'calendar_id' => $this->calendar->id,
        'title' => 'With reminders',
        'starts_at' => '2026-08-03T09:00:00+08:00',
        'ends_at' => '2026-08-03T10:00:00+08:00',
        'reminders' => [10, 60],
    ])->assertCreated();

    $event = Event::query()->latest('id')->firstOrFail();

    expect($event->reminders()->count())->toBe(4)
        ->and($event->reminders()->where('channel', 'mail')->pluck('minutes_before')->sort()->values()->all())
        ->toBe([10, 60]);
});

test('due reminders dispatch once and never twice', function () {
    Notification::fake();

    Carbon::setTestNow('2026-08-03 00:00:00');

    $event = Event::factory()->create([
        'calendar_id' => $this->calendar->id,
        'created_by' => $this->user->id,
        'starts_at' => '2026-08-03 00:20:00',
        'ends_at' => '2026-08-03 01:00:00',
    ]);

    $event->reminders()->create([
        'user_id' => $this->user->id,
        'minutes_before' => 30,
        'channel' => 'database',
    ]);

    (new SendDueReminders)->handle(app(OccurrenceExpander::class));
    (new SendDueReminders)->handle(app(OccurrenceExpander::class));

    Notification::assertSentToTimes($this->user, EventReminderNotification::class, 1);

    Carbon::setTestNow();
});

test('reminders that are not yet due stay quiet', function () {
    Notification::fake();

    Carbon::setTestNow('2026-08-03 00:00:00');

    $event = Event::factory()->create([
        'calendar_id' => $this->calendar->id,
        'created_by' => $this->user->id,
        'starts_at' => '2026-08-03 06:00:00',
        'ends_at' => '2026-08-03 07:00:00',
    ]);

    $event->reminders()->create([
        'user_id' => $this->user->id,
        'minutes_before' => 30,
        'channel' => 'database',
    ]);

    (new SendDueReminders)->handle(app(OccurrenceExpander::class));

    Notification::assertNothingSent();

    Carbon::setTestNow();
});

test('recurring events remind per occurrence without duplicates', function () {
    Notification::fake();

    Carbon::setTestNow('2026-08-03 08:45:00');

    $event = Event::factory()->recurring('FREQ=DAILY;COUNT=5')->create([
        'calendar_id' => $this->calendar->id,
        'created_by' => $this->user->id,
        'starts_at' => '2026-08-03 09:00:00',
        'ends_at' => '2026-08-03 10:00:00',
    ]);

    $event->reminders()->create([
        'user_id' => $this->user->id,
        'minutes_before' => 30,
        'channel' => 'database',
    ]);

    // Today's occurrence is due.
    (new SendDueReminders)->handle(app(OccurrenceExpander::class));
    Notification::assertSentToTimes($this->user, EventReminderNotification::class, 1);

    // Next day: the second occurrence becomes due; still no duplicate of the first.
    Carbon::setTestNow('2026-08-04 08:45:00');
    (new SendDueReminders)->handle(app(OccurrenceExpander::class));
    Notification::assertSentToTimes($this->user, EventReminderNotification::class, 2);

    Carbon::setTestNow();
});

test('the notification center lists, marks and clears notifications', function () {
    $this->user->notify(new UserApprovedNotification($this->user));

    $index = $this->actingAs($this->user)->getJson(route('api.notifications.index'));

    $index->assertOk();
    expect($index->json('unread_count'))->toBe(1);

    $id = $index->json('data.0.id');

    $this->actingAs($this->user)->postJson(route('api.notifications.read', $id))->assertOk();

    expect($this->user->unreadNotifications()->count())->toBe(0);

    $this->actingAs($this->user)->deleteJson(route('api.notifications.clear'))->assertNoContent();

    expect($this->user->notifications()->count())->toBe(0);
});
