<?php

use App\Models\ChatMessage;
use App\Models\ChatPoll;
use App\Models\Division;
use App\Models\User;

beforeEach(function () {
    $this->division = Division::factory()->create();
    $this->member = User::factory()->create(['division_id' => $this->division->id]);
    $this->teammate = User::factory()->create(['division_id' => $this->division->id]);

    $this->message = ChatMessage::factory()->create([
        'division_id' => $this->division->id,
        'user_id' => $this->member->id,
    ]);
});

test('reactions toggle on and off', function () {
    $this->actingAs($this->teammate)
        ->postJson(route('api.chat.reactions.toggle', $this->message), ['emoji' => '❤️'])
        ->assertOk()
        ->assertJsonPath('data.0.emoji', '❤️')
        ->assertJsonPath('data.0.count', 1);

    $this->actingAs($this->teammate)
        ->postJson(route('api.chat.reactions.toggle', $this->message), ['emoji' => '❤️'])
        ->assertOk()
        ->assertJsonPath('data', []);
});

test('only the curated reaction set is accepted', function () {
    $this->actingAs($this->teammate)
        ->postJson(route('api.chat.reactions.toggle', $this->message), ['emoji' => '🦄'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('emoji');
});

test('polls are created with a message and voted on', function () {
    $response = $this->actingAs($this->member)
        ->postJson(route('api.chat.messages.store'), [
            'poll' => [
                'question' => 'Team lunch where?',
                'options' => ['Jollibee', 'Mang Inasal', 'Sushi'],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.poll.question', 'Team lunch where?');

    $pollId = $response->json('data.poll.id');
    $optionIds = collect($response->json('data.poll.options'))->pluck('id');

    expect($optionIds)->toHaveCount(3);

    // Single-choice: a new vote replaces the previous one.
    $this->actingAs($this->teammate)
        ->putJson(route('api.chat.polls.votes', $pollId), ['option_ids' => [$optionIds[0]]])
        ->assertOk();

    $poll = $this->actingAs($this->teammate)
        ->putJson(route('api.chat.polls.votes', $pollId), ['option_ids' => [$optionIds[1]]])
        ->assertOk()
        ->json('data');

    expect(collect($poll['options'][0]['voters']))->toHaveCount(0)
        ->and(collect($poll['options'][1]['voters'])->pluck('id'))->toContain($this->teammate->id);

    // Unvote via empty array.
    $poll = $this->actingAs($this->teammate)
        ->putJson(route('api.chat.polls.votes', $pollId), ['option_ids' => []])
        ->assertOk()
        ->json('data');

    expect(collect($poll['options'])->flatMap(fn ($o) => $o['voters']))->toHaveCount(0);
});

test('single-choice polls refuse multiple selections', function () {
    $response = $this->actingAs($this->member)
        ->postJson(route('api.chat.messages.store'), [
            'poll' => ['question' => 'Pick one', 'options' => ['A', 'B']],
        ])->assertCreated();

    $optionIds = collect($response->json('data.poll.options'))->pluck('id')->all();

    $this->actingAs($this->teammate)
        ->putJson(route('api.chat.polls.votes', $response->json('data.poll.id')), [
            'option_ids' => $optionIds,
        ])
        ->assertUnprocessable();
});

test('multiple-choice polls accept several selections', function () {
    $response = $this->actingAs($this->member)
        ->postJson(route('api.chat.messages.store'), [
            'poll' => [
                'question' => 'Which days work?',
                'options' => ['Mon', 'Tue', 'Wed'],
                'allows_multiple' => true,
            ],
        ])->assertCreated();

    $optionIds = collect($response->json('data.poll.options'))->pluck('id');

    $poll = $this->actingAs($this->teammate)
        ->putJson(route('api.chat.polls.votes', $response->json('data.poll.id')), [
            'option_ids' => [$optionIds[0], $optionIds[2]],
        ])
        ->assertOk()
        ->json('data');

    expect(collect($poll['options'][0]['voters'])->pluck('id'))->toContain($this->teammate->id)
        ->and(collect($poll['options'][2]['voters'])->pluck('id'))->toContain($this->teammate->id);
});

test('poll options from another poll are rejected', function () {
    $mine = $this->actingAs($this->member)
        ->postJson(route('api.chat.messages.store'), [
            'poll' => ['question' => 'Mine', 'options' => ['A', 'B']],
        ])->assertCreated();

    $other = $this->actingAs($this->member)
        ->postJson(route('api.chat.messages.store'), [
            'poll' => ['question' => 'Other', 'options' => ['C', 'D']],
        ])->assertCreated();

    $this->actingAs($this->teammate)
        ->putJson(route('api.chat.polls.votes', $mine->json('data.poll.id')), [
            'option_ids' => [collect($other->json('data.poll.options'))->pluck('id')->first()],
        ])
        ->assertUnprocessable();
});

test('polls cannot be combined with photos and need two options', function () {
    $this->actingAs($this->member)
        ->postJson(route('api.chat.messages.store'), [
            'poll' => ['question' => 'One option only', 'options' => ['A']],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('poll.options');
});

test('votes from another division are refused', function () {
    $outsider = User::factory()->create(['division_id' => Division::factory()->create()->id]);

    $response = $this->actingAs($this->member)
        ->postJson(route('api.chat.messages.store'), [
            'poll' => ['question' => 'Private poll', 'options' => ['A', 'B']],
        ])->assertCreated();

    $this->actingAs($outsider)
        ->putJson(route('api.chat.polls.votes', $response->json('data.poll.id')), [
            'option_ids' => [],
        ])
        ->assertForbidden();

    expect(ChatPoll::query()->count())->toBe(1);
});
