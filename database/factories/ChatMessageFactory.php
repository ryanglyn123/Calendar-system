<?php

namespace Database\Factories;

use App\Models\ChatMessage;
use App\Models\Division;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatMessage>
 */
class ChatMessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            // A fresh division's room by default (the observer creates it).
            'conversation_id' => fn () => Division::factory()->create()->conversation->id,
            'user_id' => User::factory(),
            'body' => fake()->sentence(),
        ];
    }
}
