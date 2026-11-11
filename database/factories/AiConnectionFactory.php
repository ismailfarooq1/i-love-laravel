<?php

namespace Database\Factories;

use App\Models\Team;
use App\Services\ChatGptAccess;
use Illuminate\Database\Eloquent\Factories\Factory;

class AiConnectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'user_id' => fn (array $attributes) => Team::findOrFail($attributes['team_id'])->owner_id,
            'provider' => 'chatgpt', 'model' => 'test-model', 'api_key' => '', 'revision' => 1,
            'slot' => fn () => 'user-'.fake()->uuid(), 'auth_type' => 'oauth', 'shared_with_team' => false,
            'credentials' => ['client_id' => 'oaiapp_test', 'subject' => 'test-subject', 'email' => 'test@example.com', 'access_token' => 'test-access-token', 'refresh_token' => 'test-refresh-token', 'expires_at' => time() + 3600, 'scopes' => explode(' ', ChatGptAccess::SCOPES)],
        ];
    }
}
