<?php

namespace Database\Factories;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Team> */
class TeamFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->company(), 'owner_id' => User::factory()];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Team $team): void {
            $team->members()->attach($team->owner_id, ['role' => 'owner']);
        });
    }
}
