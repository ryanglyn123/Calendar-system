<?php

use App\Models\Calendar;
use App\Models\Conversation;
use App\Models\Division;
use App\Models\Event;
use App\Models\User;
use App\Services\Chat\ConversationService;

beforeEach(function () {
    $this->divisionA = Division::factory()->create();
    $this->divisionB = Division::factory()->create();
    $this->creator = User::factory()->create(['division_id' => $this->divisionA->id]);
    $this->crossDivision = User::factory()->create(['division_id' => $this->divisionB->id]);
    $this->outsider = User::factory()->create(['division_id' => $this->divisionB->id]);
});

test('anyone can create a custom group chat with cross-division members', function () {
    $response = $this->actingAs($this->creator)
        ->postJson(route('api.conversations.store'), [
            'name' => 'Festival planning',
            'member_ids' => [$this->crossDivision->id],
        ])
        ->assertCreated()
        ->assertJsonPath('data.type', 'custom')
        ->assertJsonPath('data.name', 'Festival planning');

    $conversation = Conversation::query()->findOrFail($response->json('data.id'));

    // Creator and the added cross-division member are both participants.
    expect($conversation->participants()->pluck('users.id')->all())
        ->toContain($this->creator->id, $this->crossDivision->id);

    // Both can post; the outsider (not added) cannot.
    $this->actingAs($this->crossDivision)
        ->postJson(route('api.chat.messages.store', $conversation), ['body' => 'hi'])
        ->assertCreated();

    $this->actingAs($this->outsider)
        ->getJson(route('api.chat.messages.index', $conversation))
        ->assertForbidden();
});

test('a custom chat can be linked to an event', function () {
    $calendar = Calendar::factory()->organization()->create();
    $event = Event::factory()->create(['calendar_id' => $calendar->id, 'title' => 'Board meeting']);

    $response = $this->actingAs($this->creator)
        ->postJson(route('api.conversations.store'), [
            'name' => 'Board meeting prep',
            'member_ids' => [$this->crossDivision->id],
            'event_id' => $event->id,
        ])
        ->assertCreated();

    expect($response->json('data.event.event_id'))->toBe($event->id)
        ->and($response->json('data.event.title'))->toBe('Board meeting');
});

test('participants can rename, add and remove members, and leave', function () {
    $conversation = app(ConversationService::class)->createCustom(
        $this->creator,
        'Original',
        [$this->crossDivision->id],
    );

    $newcomer = User::factory()->create(['division_id' => $this->divisionA->id]);

    // Rename + add a member.
    $this->actingAs($this->crossDivision)
        ->patchJson(route('api.conversations.update', $conversation), [
            'name' => 'Renamed',
            'member_ids' => [$this->crossDivision->id, $newcomer->id],
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed');

    expect($conversation->fresh()->participants()->pluck('users.id')->all())
        ->toContain($this->creator->id, $this->crossDivision->id, $newcomer->id);

    // Leaving drops the member.
    $this->actingAs($newcomer)
        ->postJson(route('api.conversations.leave', $conversation))
        ->assertOk();

    expect($conversation->fresh()->participants()->pluck('users.id')->all())
        ->not->toContain($newcomer->id);
});

test('non-members cannot edit a custom chat', function () {
    $conversation = app(ConversationService::class)->createCustom(
        $this->creator,
        'Private',
        [$this->crossDivision->id],
    );

    $this->actingAs($this->outsider)
        ->patchJson(route('api.conversations.update', $conversation), ['name' => 'Hijacked'])
        ->assertForbidden();
});

test('division rooms cannot be edited through the conversation routes', function () {
    $room = $this->divisionA->conversation;

    $this->actingAs($this->creator)
        ->patchJson(route('api.conversations.update', $room), ['name' => 'Nope'])
        ->assertForbidden();
});

test('the conversation list includes division and custom chats', function () {
    app(ConversationService::class)->createCustom(
        $this->creator,
        'My group',
        [$this->crossDivision->id],
    );

    $list = collect($this->actingAs($this->creator)
        ->getJson(route('api.conversations.index'))
        ->assertOk()
        ->json('data'));

    expect($list->pluck('type'))->toContain('division')->toContain('custom')
        ->and($list->firstWhere('type', 'custom')['name'])->toBe('My group');
});

test('participant candidate search finds active users', function () {
    $target = User::factory()->create(['name' => 'Zorro Uniquename', 'status' => 'active']);

    $results = collect($this->actingAs($this->creator)
        ->getJson(route('api.conversations.candidates', ['q' => 'Uniquename']))
        ->assertOk()
        ->json('data'));

    expect($results->pluck('id'))->toContain($target->id);

    // An empty term lists people right away (live search before typing).
    $initial = collect($this->actingAs($this->creator)
        ->getJson(route('api.conversations.candidates'))
        ->assertOk()
        ->json('data'));

    expect($initial)->not->toBeEmpty();
});
