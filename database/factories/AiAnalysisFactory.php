<?php

namespace Database\Factories;

use App\Models\AiConnection;
use App\Models\BacklogProject;
use Illuminate\Database\Eloquent\Factories\Factory;

class AiAnalysisFactory extends Factory
{
    public function definition(): array
    {
        return ['backlog_project_id' => BacklogProject::factory(), 'user_id' => fn (array $attributes) => BacklogProject::findOrFail($attributes['backlog_project_id'])->team->owner_id,
            'ai_connection_id' => fn (array $attributes) => AiConnection::factory()->create(['team_id' => BacklogProject::findOrFail($attributes['backlog_project_id'])->team_id])->id,
            'connection_revision' => 1, 'provider' => 'openai', 'model' => 'test-model',
            'generation' => fn (array $attributes) => BacklogProject::findOrFail($attributes['backlog_project_id'])->generation,
            'project_version' => 1, 'input' => ['project' => [], 'parent' => null, 'documents' => []]];
    }
}
