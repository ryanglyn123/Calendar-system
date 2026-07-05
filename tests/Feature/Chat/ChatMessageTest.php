<?php

use App\Models\ChatMessage;
use App\Models\Division;
use App\Models\User;

beforeEach(function () {
    $this->division = Division::factory()->create();
    $this->room = $this->division->conversation;
    $this->member = User::factory()->create(['division_id' => $this->division->id]);
    $this->teammate = User::factory()->create(['division_id' => $this->division->id]);
    $this->outsider = User::factory()->create(['division_id' => Division::factory()->create()->id]);
});

test('members can send and list messages', function () {
    $this->actingAs($this->member)
        ->postJson(route('api.chat.messages.store', $this->room), ['body' => 'Hello team, see https://fdcp.gov.ph'])
        ->assertCreated()
        ->assertJsonPath('data.body', 'Hello team, see https://fdcp.gov.ph')
        ->assertJsonPath('data.user.id', $this->member->id);

    $this->actingAs($this->teammate)
        ->getJson(route('api.chat.messages.index', $this->room))
        ->assertOk()
        ->assertJsonPath('messages.0.body', 'Hello team, see https://fdcp.gov.ph')
        ->assertJsonPath('has_more', false);
});

test('a message needs a body, photos, or a poll', function () {
    $this->actingAs($this->member)
        ->postJson(route('api.chat.messages.store', $this->room), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('body');
});

test('replies must target a message in the same conversation', function () {
    $foreign = ChatMessage::factory()->create();

    $this->actingAs($this->member)
        ->postJson(route('api.chat.messages.store', $this->room), [
            'body' => 'replying',
            'reply_to_id' => $foreign->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reply_to_id');

    $own = ChatMessage::factory()->create([
        'conversation_id' => $this->room->id,
        'user_id' => $this->teammate->id,
    ]);

    $this->actingAs($this->member)
        ->postJson(route('api.chat.messages.store', $this->room), [
            'body' => 'replying',
            'reply_to_id' => $own->id,
        ])
        ->assertCreated()
        ->assertJsonPath('data.reply_to.id', $own->id);
});

test('pagination pages backwards with before_id', function () {
    $messages = collect(range(1, 35))->map(fn (int $i) => ChatMessage::factory()->create([
        'conversation_id' => $this->room->id,
        'user_id' => $this->member->id,
        'body' => "Message {$i}",
    ]));

    $first = $this->actingAs($this->member)
        ->getJson(route('api.chat.messages.index', $this->room))
        ->assertOk()
        ->assertJsonPath('has_more', true)
        ->json();

    expect(count($first['messages']))->toBe(30)
        ->and($first['messages'][0]['id'])->toBe($messages->last()->id);

    $oldestSeen = collect($first['messages'])->min('id');

    $second = $this->actingAs($this->member)
        ->getJson(route('api.chat.messages.index', [$this->room, 'before_id' => $oldestSeen]))
        ->assertOk()
        ->assertJsonPath('has_more', false)
        ->json();

    expect(count($second['messages']))->toBe(5);
});

test('deleting your own message leaves a tombstone without content', function () {
    $message = ChatMessage::factory()->create([
        'conversation_id' => $this->room->id,
        'user_id' => $this->member->id,
        'body' => 'Delete me',
    ]);

    $this->actingAs($this->member)
        ->deleteJson(route('api.chat.messages.destroy', $message))
        ->assertOk();

    $listed = collect($this->actingAs($this->teammate)
        ->getJson(route('api.chat.messages.index', $this->room))
        ->json('messages'))
        ->firstWhere('id', $message->id);

    expect($listed['is_deleted'])->toBeTrue()
        ->and($listed['body'])->toBeNull()
        ->and($message->refresh()->body)->toBeNull();
});

test('you cannot delete someone else\'s message', function () {
    $message = ChatMessage::factory()->create([
        'conversation_id' => $this->room->id,
        'user_id' => $this->teammate->id,
    ]);

    $this->actingAs($this->member)
        ->deleteJson(route('api.chat.messages.destroy', $message))
        ->assertForbidden();
});

test('other divisions cannot read or write this room', function () {
    $this->actingAs($this->outsider)
        ->getJson(route('api.chat.messages.index', $this->room))
        ->assertForbidden();

    $this->actingAs($this->outsider)
        ->postJson(route('api.chat.messages.store', $this->room), ['body' => 'intrusion'])
        ->assertForbidden();
});

test('super admins from another division get no special access', function () {
    $superAdmin = User::factory()->create(['division_id' => Division::factory()->create()->id]);
    $superAdmin->assignRole('super_admin');

    $message = ChatMessage::factory()->create([
        'conversation_id' => $this->room->id,
        'user_id' => $this->member->id,
        'body' => 'division only',
    ]);

    $this->actingAs($superAdmin)
        ->getJson(route('api.chat.messages.index', $this->room))
        ->assertForbidden();

    $this->actingAs($superAdmin)
        ->postJson(route('api.chat.reactions.toggle', $message), ['emoji' => '👍'])
        ->assertForbidden();
});

test('users without a division cannot access a division room', function () {
    $nobody = User::factory()->create(['division_id' => null]);

    $this->actingAs($nobody)
        ->getJson(route('api.chat.messages.index', $this->room))
        ->assertForbidden();

    $this->actingAs($nobody)
        ->postJson(route('api.chat.messages.store', $this->room), ['body' => 'hi'])
        ->assertForbidden();
});
