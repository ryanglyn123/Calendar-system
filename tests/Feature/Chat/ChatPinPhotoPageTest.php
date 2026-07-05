<?php

use App\Models\ChatMessage;
use App\Models\Division;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->division = Division::factory()->create();
    $this->member = User::factory()->create(['division_id' => $this->division->id]);
    $this->teammate = User::factory()->create(['division_id' => $this->division->id]);
    $this->outsider = User::factory()->create(['division_id' => Division::factory()->create()->id]);
});

test('any member can pin and unpin messages', function () {
    $message = ChatMessage::factory()->create([
        'division_id' => $this->division->id,
        'user_id' => $this->member->id,
        'body' => 'Important announcement',
    ]);

    $this->actingAs($this->teammate)
        ->postJson(route('api.chat.pin.toggle', $message))
        ->assertOk()
        ->assertJsonPath('data.is_pinned', true);

    $pinned = collect($this->actingAs($this->member)
        ->getJson(route('api.chat.pinned'))
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

test('any member can change the group photo, outsiders cannot see it', function () {
    Storage::fake('local');

    $this->actingAs($this->member)
        ->post(route('api.chat.photo.update'), [
            'photo' => UploadedFile::fake()->image('group.png', 256, 256),
        ], ['Accept' => 'application/json'])
        ->assertCreated();

    expect($this->division->refresh()->chat_photo_path)->not->toBeNull()
        ->and(Storage::disk('local')->exists($this->division->chat_photo_path))->toBeTrue();

    $this->actingAs($this->teammate)->get(route('api.chat.photo.show'))->assertOk();

    // The outsider's own division has no photo; 404, and never this file.
    $this->actingAs($this->outsider)->get(route('api.chat.photo.show'))->assertNotFound();

    // Replacing the photo removes the old file.
    $old = $this->division->chat_photo_path;

    $this->actingAs($this->teammate)
        ->post(route('api.chat.photo.update'), [
            'photo' => UploadedFile::fake()->image('newer.png', 256, 256),
        ], ['Accept' => 'application/json'])
        ->assertCreated();

    expect(Storage::disk('local')->exists($old))->toBeFalse();
});

test('the chat page inlines the latest messages and members', function () {
    ChatMessage::factory()->create([
        'division_id' => $this->division->id,
        'user_id' => $this->teammate->id,
        'body' => 'Welcome!',
    ]);

    $props = $this->actingAs($this->member)->get(route('chat.index'))->inertiaProps();

    expect($props['division']['id'])->toBe($this->division->id)
        ->and(collect($props['initialPage']['messages'])->pluck('body'))->toContain('Welcome!')
        ->and(collect($props['members'])->pluck('id'))->toContain($this->member->id, $this->teammate->id);
});

test('users without a division are redirected from the chat page', function () {
    $nobody = User::factory()->create(['division_id' => null]);

    $this->actingAs($nobody)
        ->get(route('chat.index'))
        ->assertRedirect(route('dashboard'));
});
