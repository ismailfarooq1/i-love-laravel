<?php

namespace Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class AiConnectionFactory extends Factory
{
    public function definition(): array
    {
        return ['team_id' => Team::factory(), 'provider' => 'openai', 'model' => 'test-model', 'api_key' => 'test-ai-key-never-real', 'revision' => 1];
    }
}
