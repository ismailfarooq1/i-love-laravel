<?php

namespace Database\Factories;

use App\Models\BacklogProject;
use App\Models\GitHubConnection;
use App\Models\GitHubIssue;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<GitHubIssue> */
class GitHubIssueFactory extends Factory
{
    public function definition(): array
    {
        return ['backlog_project_id' => BacklogProject::factory()->state(['workspace' => (string) Str::uuid(), 'team_id' => Team::factory()]), 'generation' => fn (array $attributes): string => BacklogProject::findOrFail($attributes['backlog_project_id'])->generation, 'github_connection_id' => fn (array $attributes): int => GitHubConnection::factory()->create(['team_id' => BacklogProject::findOrFail($attributes['backlog_project_id'])->team_id])->id, 'node_id' => 'root', 'marker' => (string) Str::uuid(), 'github_id' => (string) fake()->unique()->numberBetween(100, 999999), 'number' => fake()->numberBetween(1, 1000), 'creation_status' => 'created'];
    }
}
