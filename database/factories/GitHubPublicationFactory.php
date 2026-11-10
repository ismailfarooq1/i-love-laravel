<?php

namespace Database\Factories;

use App\Models\BacklogProject;
use App\Models\GitHubConnection;
use App\Models\GitHubPublication;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<GitHubPublication> */
class GitHubPublicationFactory extends Factory
{
    public function definition(): array
    {
        return ['backlog_project_id' => BacklogProject::factory()->state(['workspace' => (string) Str::uuid(), 'team_id' => Team::factory()]), 'user_id' => fn (array $attributes): int => BacklogProject::findOrFail($attributes['backlog_project_id'])->team->owner_id, 'github_connection_id' => fn (array $attributes): int => GitHubConnection::factory()->create(['team_id' => BacklogProject::findOrFail($attributes['backlog_project_id'])->team_id])->id, 'generation' => fn (array $attributes): string => BacklogProject::findOrFail($attributes['backlog_project_id'])->generation, 'repository_id' => '123', 'repository_full_name' => 'example/repository', 'items' => []];
    }
}
