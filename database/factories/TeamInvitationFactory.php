<?php

namespace Database\Factories;

use App\Models\Team;
use App\Models\TeamInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<TeamInvitation> */
class TeamInvitationFactory extends Factory
{
    public function definition(): array
    {
        return ['team_id' => Team::factory(), 'email' => fake()->unique()->safeEmail(), 'role' => 'viewer', 'token_hash' => hash('sha256', Str::random(64)), 'expires_at' => now()->addDays(7)];
    }
}
