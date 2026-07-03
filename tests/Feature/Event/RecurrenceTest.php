<?php

use App\Models\Calendar;
use App\Models\Event;
use App\Models\User;
use App\Services\Event\EventService;
use App\Services\Event\RecurrenceService;
use Illuminate\Support\Collection;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->calendar = Calendar::factory()->ownedBy($this->user)->create();
});

function windowFor(string $start, string $end): array
{
    return ['start' => $start, 'end' => $end];
}

function occurrencesFor($test, User $user, array $window): Collection
{
    return collect(
        $test->actingAs($user)
            ->getJson(route('api.events.index', $window))
            ->json('data'),
    );
}

test('a weekly series expands into one occurrence per week', function () {
    Event::factory()->recurring('FREQ=WEEKLY')->create([
        'calendar_id' => $this->calendar->id,
        'created_by' => $this->user->id,
        'starts_at' => '2026-08-03 01:00:00', // Mondays 09:00 Manila
        'ends_at' => '2026-08-03 02:00:00',
    ]);

    $occurrences = occurrencesFor($this, $this->user, windowFor('2026-08-01T00:00:00Z', '2026-09-01T00:00:00Z'));

    expect($occurrences)->toHaveCount(5)
        ->and($occurrences->pluck('starts_at')->first())->toContain('2026-08-03')
        ->and($occurrences->every(fn ($o) => str_contains($o['starts_at'], 'T01:00:00')))->toBeTrue()
        ->and($occurrences->every(fn ($o) => $o['is_recurring']))->toBeTrue();
});

test('COUNT rules stop after the given number of occurrences', function () {
    $event = Event::factory()->recurring('FREQ=DAILY;COUNT=3')->create([
        'calendar_id' => $this->calendar->id,
        'created_by' => $this->user->id,
        'starts_at' => '2026-08-03 01:00:00',
        'ends_at' => '2026-08-03 02:00:00',
    ]);

    app(EventService::class)->update($event, []);

    $occurrences = occurrencesFor($this, $this->user, windowFor('2026-08-01T00:00:00Z', '2026-09-01T00:00:00Z'));

    expect($occurrences)->toHaveCount(3)
        ->and($event->refresh()->recurrence_until?->toDateString())->toBe('2026-08-05');
});

test('UNTIL rules stop at the boundary', function () {
    Event::factory()->recurring('FREQ=DAILY;UNTIL=20260805T235959Z')->create([
        'calendar_id' => $this->calendar->id,
        'created_by' => $this->user->id,
        'starts_at' => '2026-08-03 01:00:00',
        'ends_at' => '2026-08-03 02:00:00',
    ]);

    $occurrences = occurrencesFor($this, $this->user, windowFor('2026-08-01T00:00:00Z', '2026-09-01T00:00:00Z'));

    expect($occurrences)->toHaveCount(3);
});

test('monthly on the 31st skips shorter months', function () {
    Event::factory()->recurring('FREQ=MONTHLY')->create([
        'calendar_id' => $this->calendar->id,
        'created_by' => $this->user->id,
        'starts_at' => '2026-01-31 01:00:00',
        'ends_at' => '2026-01-31 02:00:00',
    ]);

    $february = occurrencesFor($this, $this->user, windowFor('2026-02-01T00:00:00Z', '2026-03-01T00:00:00Z'));
    $march = occurrencesFor($this, $this->user, windowFor('2026-03-01T00:00:00Z', '2026-04-01T00:00:00Z'));

    expect($february)->toHaveCount(0)
        ->and($march)->toHaveCount(1)
        ->and($march->first()['starts_at'])->toContain('2026-03-31');
});

test('editing one occurrence creates an override that shadows the slot', function () {
    $event = Event::factory()->recurring('FREQ=DAILY;COUNT=5')->create([
        'calendar_id' => $this->calendar->id,
        'created_by' => $this->user->id,
        'starts_at' => '2026-08-03 01:00:00',
        'ends_at' => '2026-08-03 02:00:00',
    ]);

    $this->actingAs($this->user)->patchJson(route('api.events.occurrence.update', $event), [
        'original_starts_at' => '2026-08-04T01:00:00Z',
        'scope' => 'this',
        'title' => 'Moved instance',
        'starts_at' => '2026-08-04T05:00:00Z',
        'ends_at' => '2026-08-04T06:00:00Z',
    ])->assertOk();

    $occurrences = occurrencesFor($this, $this->user, windowFor('2026-08-01T00:00:00Z', '2026-09-01T00:00:00Z'));

    expect($occurrences)->toHaveCount(5)
        ->and($occurrences->firstWhere('title', 'Moved instance')['starts_at'])->toContain('T05:00:00')
        ->and($occurrences->filter(fn ($o) => str_contains($o['starts_at'], '2026-08-04T01:00:00')))->toHaveCount(0);
});

test('deleting one occurrence cancels only that slot', function () {
    $event = Event::factory()->recurring('FREQ=DAILY;COUNT=5')->create([
        'calendar_id' => $this->calendar->id,
        'created_by' => $this->user->id,
        'starts_at' => '2026-08-03 01:00:00',
        'ends_at' => '2026-08-03 02:00:00',
    ]);

    $this->actingAs($this->user)->deleteJson(route('api.events.occurrence.destroy', $event), [
        'original_starts_at' => '2026-08-04T01:00:00Z',
        'scope' => 'this',
    ])->assertNoContent();

    $occurrences = occurrencesFor($this, $this->user, windowFor('2026-08-01T00:00:00Z', '2026-09-01T00:00:00Z'));

    expect($occurrences)->toHaveCount(4)
        ->and($occurrences->filter(fn ($o) => str_contains($o['starts_at'], '2026-08-04')))->toHaveCount(0);
});

test('this-and-following splits the series into two', function () {
    $event = Event::factory()->recurring('FREQ=DAILY;COUNT=6')->create([
        'calendar_id' => $this->calendar->id,
        'created_by' => $this->user->id,
        'starts_at' => '2026-08-03 01:00:00',
        'ends_at' => '2026-08-03 02:00:00',
        'title' => 'Original series',
    ]);

    $this->actingAs($this->user)->patchJson(route('api.events.occurrence.update', $event), [
        'original_starts_at' => '2026-08-06T01:00:00Z',
        'scope' => 'following',
        'title' => 'New series',
        'starts_at' => '2026-08-06T03:00:00Z',
        'ends_at' => '2026-08-06T04:00:00Z',
    ])->assertOk();

    $occurrences = occurrencesFor($this, $this->user, windowFor('2026-08-01T00:00:00Z', '2026-09-01T00:00:00Z'));

    $original = $occurrences->where('title', 'Original series');
    $renamed = $occurrences->where('title', 'New series');

    // 3 remain on the old series (Aug 3-5), 3 continue on the new one at 03:00.
    expect($original)->toHaveCount(3)
        ->and($renamed)->toHaveCount(3)
        ->and($renamed->every(fn ($o) => str_contains($o['starts_at'], 'T03:00:00')))->toBeTrue();
});

test('deleting this-and-following truncates the series', function () {
    $event = Event::factory()->recurring('FREQ=DAILY;COUNT=6')->create([
        'calendar_id' => $this->calendar->id,
        'created_by' => $this->user->id,
        'starts_at' => '2026-08-03 01:00:00',
        'ends_at' => '2026-08-03 02:00:00',
    ]);

    $this->actingAs($this->user)->deleteJson(route('api.events.occurrence.destroy', $event), [
        'original_starts_at' => '2026-08-06T01:00:00Z',
        'scope' => 'following',
    ])->assertNoContent();

    $occurrences = occurrencesFor($this, $this->user, windowFor('2026-08-01T00:00:00Z', '2026-09-01T00:00:00Z'));

    expect($occurrences)->toHaveCount(3);
});

test('editing all events shifts every occurrence by the time delta', function () {
    $event = Event::factory()->recurring('FREQ=DAILY;COUNT=4')->create([
        'calendar_id' => $this->calendar->id,
        'created_by' => $this->user->id,
        'starts_at' => '2026-08-03 01:00:00',
        'ends_at' => '2026-08-03 02:00:00',
    ]);

    // Move the Aug 5 occurrence two hours later, scope all.
    $this->actingAs($this->user)->patchJson(route('api.events.occurrence.update', $event), [
        'original_starts_at' => '2026-08-05T01:00:00Z',
        'scope' => 'all',
        'starts_at' => '2026-08-05T03:00:00Z',
        'ends_at' => '2026-08-05T04:00:00Z',
    ])->assertOk();

    $occurrences = occurrencesFor($this, $this->user, windowFor('2026-08-01T00:00:00Z', '2026-09-01T00:00:00Z'));

    expect($occurrences)->toHaveCount(4)
        ->and($occurrences->every(fn ($o) => str_contains($o['starts_at'], 'T03:00:00')))->toBeTrue();
});

test('an override moved into a different window still appears there', function () {
    $event = Event::factory()->recurring('FREQ=DAILY;COUNT=3')->create([
        'calendar_id' => $this->calendar->id,
        'created_by' => $this->user->id,
        'starts_at' => '2026-08-28 01:00:00',
        'ends_at' => '2026-08-28 02:00:00',
    ]);

    // Move the Aug 30 occurrence into September.
    $this->actingAs($this->user)->patchJson(route('api.events.occurrence.update', $event), [
        'original_starts_at' => '2026-08-30T01:00:00Z',
        'scope' => 'this',
        'starts_at' => '2026-09-02T01:00:00Z',
        'ends_at' => '2026-09-02T02:00:00Z',
    ])->assertOk();

    $september = occurrencesFor($this, $this->user, windowFor('2026-09-01T00:00:00Z', '2026-10-01T00:00:00Z'));

    expect($september->filter(fn ($o) => str_contains($o['starts_at'], '2026-09-02')))->toHaveCount(1);
});

test('invalid recurrence rules are rejected', function () {
    $this->actingAs($this->user)->postJson(route('api.events.store'), [
        'calendar_id' => $this->calendar->id,
        'title' => 'Broken rule',
        'starts_at' => '2026-08-03T09:00:00+08:00',
        'ends_at' => '2026-08-03T10:00:00+08:00',
        'rrule' => 'FREQ=SOMETIMES;WHEN=FULLMOON',
    ])->assertUnprocessable()->assertJsonValidationErrors('rrule');
});

test('recurrence_until is set on creation of a finite series', function () {
    $response = $this->actingAs($this->user)->postJson(route('api.events.store'), [
        'calendar_id' => $this->calendar->id,
        'title' => 'Weekly sync',
        'starts_at' => '2026-08-03T09:00:00+08:00',
        'ends_at' => '2026-08-03T10:00:00+08:00',
        'rrule' => 'FREQ=WEEKLY;COUNT=4',
    ]);

    $response->assertCreated();

    $event = Event::query()->latest('id')->firstOrFail();

    expect($event->recurrence_until?->toDateString())->toBe('2026-08-24');

    $infinite = Event::factory()->recurring('FREQ=WEEKLY')->create([
        'calendar_id' => $this->calendar->id,
        'created_by' => $this->user->id,
    ]);

    app(RecurrenceService::class);

    expect($infinite->recurrence_until)->toBeNull();
});
