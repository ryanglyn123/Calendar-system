<?php

use App\Models\ChatAttachment;
use App\Models\ChatMessage;
use App\Models\Division;
use App\Models\User;
use App\Services\Chat\ChatService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->division = Division::factory()->create();
    $this->member = User::factory()->create(['division_id' => $this->division->id]);
    $this->teammate = User::factory()->create(['division_id' => $this->division->id]);
    $this->outsider = User::factory()->create(['division_id' => Division::factory()->create()->id]);
});

test('photos upload to the private disk and stream to members only', function () {
    Storage::fake('local');

    $response = $this->actingAs($this->member)
        ->post(route('api.chat.messages.store'), [
            'body' => 'photo drop',
            'photos' => [UploadedFile::fake()->image('team.jpg', 640, 480)],
        ], ['Accept' => 'application/json'])
        ->assertCreated();

    $attachment = $response->json('data.attachments.0');

    expect($attachment['width'])->toBe(640)
        ->and($attachment['height'])->toBe(480)
        ->and(Storage::disk('local')->exists(
            ChatAttachment::query()->firstOrFail()->path,
        ))->toBeTrue();

    $this->actingAs($this->teammate)->get($attachment['url'])->assertOk();
    $this->actingAs($this->outsider)->get($attachment['url'])->assertForbidden();
});

test('upload limits are enforced', function () {
    Storage::fake('local');

    // Too many photos.
    $this->actingAs($this->member)
        ->post(route('api.chat.messages.store'), [
            'photos' => collect(range(1, 5))
                ->map(fn () => UploadedFile::fake()->image('x.jpg'))
                ->all(),
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('photos');

    // Wrong type.
    $this->actingAs($this->member)
        ->post(route('api.chat.messages.store'), [
            'photos' => [UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf')],
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable();

    // Oversized.
    $this->actingAs($this->member)
        ->post(route('api.chat.messages.store'), [
            'photos' => [UploadedFile::fake()->image('big.jpg')->size(6000)],
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable();
});

test('deleting a message removes its files', function () {
    Storage::fake('local');

    $this->actingAs($this->member)
        ->post(route('api.chat.messages.store'), [
            'photos' => [UploadedFile::fake()->image('gone.jpg')],
        ], ['Accept' => 'application/json'])
        ->assertCreated();

    $attachment = ChatAttachment::query()->firstOrFail();
    $message = $attachment->message;

    $this->actingAs($this->member)
        ->deleteJson(route('api.chat.messages.destroy', $message))
        ->assertOk();

    expect(Storage::disk('local')->exists($attachment->path))->toBeFalse();
});

test('unread counts exclude own and deleted messages and clamp monotonically', function () {
    $mine = ChatMessage::factory()->create([
        'division_id' => $this->division->id,
        'user_id' => $this->member->id,
    ]);
    $fromTeammate = ChatMessage::factory()->create([
        'division_id' => $this->division->id,
        'user_id' => $this->teammate->id,
    ]);
    ChatMessage::factory()->create([
        'division_id' => $this->division->id,
        'user_id' => $this->teammate->id,
    ]);

    $chat = app(ChatService::class);

    // Own message never counts as unread.
    expect($chat->unreadCount($this->member))->toBe(2);

    $this->actingAs($this->member)
        ->postJson(route('api.chat.read'), ['last_read_message_id' => $fromTeammate->id])
        ->assertOk();

    expect($chat->unreadCount($this->member->refresh()))->toBe(1);

    // Regressing the marker is a no-op.
    $this->actingAs($this->member)
        ->postJson(route('api.chat.read'), ['last_read_message_id' => $mine->id])
        ->assertOk();

    expect($chat->unreadCount($this->member->refresh()))->toBe(1);
});

test('the shared chat props expose division and unread count', function () {
    ChatMessage::factory()->create([
        'division_id' => $this->division->id,
        'user_id' => $this->teammate->id,
    ]);

    $props = $this->actingAs($this->member)->get(route('dashboard'))->inertiaProps();

    expect($props['chatDivision']['id'])->toBe($this->division->id)
        ->and($props['chatUnread'])->toBe(1);

    $nobody = User::factory()->create(['division_id' => null]);
    $props = $this->actingAs($nobody)->get(route('dashboard'))->inertiaProps();

    expect($props['chatDivision'])->toBeNull()
        ->and($props['chatUnread'])->toBe(0);
});

test('members endpoint returns read positions', function () {
    $message = ChatMessage::factory()->create([
        'division_id' => $this->division->id,
        'user_id' => $this->member->id,
    ]);

    $this->actingAs($this->teammate)
        ->postJson(route('api.chat.read'), ['last_read_message_id' => $message->id])
        ->assertOk();

    $members = collect($this->actingAs($this->member)
        ->getJson(route('api.chat.members'))
        ->assertOk()
        ->json('data'));

    expect($members->pluck('id'))->toContain($this->member->id, $this->teammate->id)
        ->and($members->firstWhere('id', $this->teammate->id)['last_read_message_id'])->toBe($message->id);
});
