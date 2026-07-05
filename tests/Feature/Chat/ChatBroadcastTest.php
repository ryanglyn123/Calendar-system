<?php

use App\Events\Chat\ChatBroadcast;
use App\Models\ChatMessage;
use App\Models\Division;
use App\Models\User;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->division = Division::factory()->create();
    $this->room = $this->division->conversation;
    $this->member = User::factory()->create(['division_id' => $this->division->id]);
    $this->teammate = User::factory()->create(['division_id' => $this->division->id]);
});

test('sending a message broadcasts message.sent on the conversation channel', function () {
    Event::fake([ChatBroadcast::class]);

    $this->actingAs($this->member)
        ->postJson(route('api.chat.messages.store', $this->room), ['body' => 'live'])
        ->assertCreated();

    Event::assertDispatched(ChatBroadcast::class, function (ChatBroadcast $event) {
        return $event->name === 'message.sent'
            && $event->conversationId === $this->room->id
            && $event->broadcastOn()->name === "private-conversation.{$this->room->id}.chat"
            && $event->payload['body'] === 'live';
    });
});

test('reactions, reads, and pins broadcast their updates', function () {
    Event::fake([ChatBroadcast::class]);

    $message = ChatMessage::factory()->create([
        'conversation_id' => $this->room->id,
        'user_id' => $this->member->id,
    ]);

    $this->actingAs($this->teammate)
        ->postJson(route('api.chat.reactions.toggle', $message), ['emoji' => '👍'])
        ->assertOk();

    $this->actingAs($this->teammate)
        ->postJson(route('api.chat.read', $this->room), ['last_read_message_id' => $message->id])
        ->assertOk();

    $this->actingAs($this->teammate)
        ->postJson(route('api.chat.pin.toggle', $message))
        ->assertOk();

    Event::assertDispatched(ChatBroadcast::class, fn (ChatBroadcast $e) => $e->name === 'reaction.updated');
    Event::assertDispatched(ChatBroadcast::class, fn (ChatBroadcast $e) => $e->name === 'read.updated'
        && $e->payload['user_id'] === $this->teammate->id);
    Event::assertDispatched(ChatBroadcast::class, fn (ChatBroadcast $e) => $e->name === 'message.pinned');
});

test('the channel authorizes members and rejects outsiders', function () {
    // The test env uses the null broadcaster, whose auth() lets anyone in;
    // the real driver exercises the channel callback in routes/channels.php.
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => '12345',
    ]);

    // Channels bind to the driver active when they were declared (the null
    // one, at boot); re-declare them on the reverb driver we just selected.
    require base_path('routes/channels.php');

    $outsider = User::factory()->create(['division_id' => Division::factory()->create()->id]);

    $this->actingAs($this->member)
        ->post('/broadcasting/auth', [
            'channel_name' => "private-conversation.{$this->room->id}.chat",
            'socket_id' => '123.456',
        ])
        ->assertOk();

    $this->actingAs($outsider)
        ->post('/broadcasting/auth', [
            'channel_name' => "private-conversation.{$this->room->id}.chat",
            'socket_id' => '123.456',
        ])
        ->assertForbidden();
});
