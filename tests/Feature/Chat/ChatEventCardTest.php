<?php

use App\Models\Calendar;
use App\Models\Division;
use App\Models\Event;
use App\Models\User;

beforeEach(function () {
    $this->division = Division::factory()->create();
    $this->room = $this->division->conversation;
    $this->member = User::factory()->create(['division_id' => $this->division->id]);
});

test('sharing an event posts a server-built card that deep-links to it', function () {
    $calendar = Calendar::factory()->organization()->create();
    $event = Event::factory()->create([
        'calendar_id' => $calendar->id,
        'title' => 'Town hall',
        'location' => 'Cinematheque Centre',
    ]);

    $response = $this->actingAs($this->member)
        ->postJson(route('api.chat.messages.store', $this->room), ['event_id' => $event->id])
        ->assertCreated();

    $card = $response->json('data.event_card');

    expect($card['event_id'])->toBe($event->id)
        ->and($card['title'])->toBe('Town hall')
        ->and($card['location'])->toBe('Cinematheque Centre')
        ->and($card['url'])->toStartWith('/calendar/public?event='.$event->id.'&date=');
});

test('sharing an event the user cannot view is rejected', function () {
    $owner = User::factory()->create();
    $personal = Calendar::factory()->ownedBy($owner)->create();
    $event = Event::factory()->create(['calendar_id' => $personal->id]);

    $this->actingAs($this->member)
        ->postJson(route('api.chat.messages.store', $this->room), ['event_id' => $event->id])
        ->assertForbidden();
});

test('the card survives the message page payload', function () {
    $calendar = Calendar::factory()->organization()->create();
    $event = Event::factory()->create(['calendar_id' => $calendar->id]);

    $this->actingAs($this->member)
        ->postJson(route('api.chat.messages.store', $this->room), ['event_id' => $event->id])
        ->assertCreated();

    $this->actingAs($this->member)
        ->getJson(route('api.chat.messages.index', $this->room))
        ->assertOk()
        ->assertJsonPath('messages.0.event_card.event_id', $event->id);
});
