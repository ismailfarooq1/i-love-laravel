<?php

namespace Database\Factories;

use App\Models\GitHubConnection;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GitHubConnection> */
class GitHubConnectionFactory extends Factory
{
    public function definition(): array
    {
        return ['team_id' => Team::factory(), 'user_id' => fn (array $attributes): int => Team::findOrFail($attributes['team_id'])->owner_id, 'mode' => 'oauth', 'github_user_id' => (string) fake()->unique()->numberBetween(100, 999999), 'login' => fake()->userName(), 'access_token' => 'fake-github-token'];
    }
}
