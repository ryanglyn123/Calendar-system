<?php

use App\Models\ChatMessage;
use App\Models\Division;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->division = Division::factory()->create();
    $this->room = $this->division->conversation;
    $this->member = User::factory()->create(['division_id' => $this->division->id]);
    $this->teammate = User::factory()->create(['division_id' => $this->division->id]);
    $this->outsider = User::factory()->create(['division_id' => Division::factory()->create()->id]);
});

test('any member can pin and unpin messages', function () {
    $message = ChatMessage::factory()->create([
        'conversation_id' => $this->room->id,
        'user_id' => $this->member->id,
        'body' => 'Important announcement',
    ]);

    $this->actingAs($this->teammate)
        ->postJson(route('api.chat.pin.toggle', $message))
        ->assertOk()
        ->assertJsonPath('data.is_pinned', true);

    $pinned = collect($this->actingAs($this->member)
        ->getJson(route('api.chat.pinned', $this->room))
        ->assertOk()
        ->json('data'));

    expect($pinned->pluck('id'))->toContain($message->id);

    $this->actingAs($this->member)
        ->postJson(route('api.chat.pin.toggle', $message))
        ->assertOk()
        ->assertJsonPath('data.is_pinned', false);

    $this->actingAs($this->outsider)
        ->postJson(route('api.chat.pin.toggle', $message))
        ->assertForbidden();
});

test('any member can change the conversation photo, outsiders cannot see it', function () {
    Storage::fake('local');

    $this->actingAs($this->member)
        ->post(route('api.chat.photo.update', $this->room), [
            'photo' => UploadedFile::fake()->image('group.png', 256, 256),
        ], ['Accept' => 'application/json'])
        ->assertCreated();

    expect($this->room->refresh()->photo_path)->not->toBeNull()
        ->and(Storage::disk('local')->exists($this->room->photo_path))->toBeTrue();

    $this->actingAs($this->teammate)->get(route('api.chat.photo.show', $this->room))->assertOk();

    // The outsider is not a member of this room.
    $this->actingAs($this->outsider)->get(route('api.chat.photo.show', $this->room))->assertForbidden();

    // Replacing the photo removes the old file.
    $old = $this->room->photo_path;

    $this->actingAs($this->teammate)
        ->post(route('api.chat.photo.update', $this->room), [
            'photo' => UploadedFile::fake()->image('newer.png', 256, 256),
        ], ['Accept' => 'application/json'])
        ->assertCreated();

    expect(Storage::disk('local')->exists($old))->toBeFalse();
});

test('the chat page exposes the conversation list', function () {
    ChatMessage::factory()->create([
        'conversation_id' => $this->room->id,
        'user_id' => $this->teammate->id,
        'body' => 'Welcome!',
    ]);

    $props = $this->actingAs($this->member)->get(route('chat.index'))->inertiaProps();

    $room = collect($props['chatConversations'])->firstWhere('id', $this->room->id);

    expect($room)->not->toBeNull()
        ->and($room['last_preview'])->toBe('Welcome!')
        ->and($props['initialConversationId'])->toBeNull();
});

test('a user with no conversations still loads the chat page', function () {
    $nobody = User::factory()->create(['division_id' => null]);

    $props = $this->actingAs($nobody)->get(route('chat.index'))->inertiaProps();

    expect($props['chatConversations'])->toHaveCount(0);
});
