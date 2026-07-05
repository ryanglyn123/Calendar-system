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
            'division_id' => Division::factory(),
            'user_id' => User::factory(),
            'body' => fake()->sentence(),
        ];
    }
}
