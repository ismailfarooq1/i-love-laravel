<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeProjectDocuments;
use App\Jobs\PublishGitHubIssues;
use App\Jobs\SynchronizeGitHubIssues;
use App\Models\AiAnalysis;
use App\Models\AiConnection;
use App\Models\BacklogProject;
use App\Models\GitHubConnection;
use App\Models\GitHubIssue;
use App\Models\GitHubPublication;
use App\Models\Team;
use App\Models\User;
use App\Services\AiPlanning;
use App\Services\GitHubPublishing;
use App\Services\GitHubSynchronization;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProjectDeletionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_deleting_project_removes_its_data_and_preserves_other_projects_and_shared_connections(): void
    {
        $project = $this->project();
        $other = $this->project($project->team);
        $github = GitHubConnection::factory()->create(['team_id' => $project->team_id, 'user_id' => $project->team->owner_id]);
        $ai = AiConnection::factory()->create(['team_id' => $project->team_id]);
        $issue = GitHubIssue::factory()->create(['backlog_project_id' => $project->id, 'github_connection_id' => $github->id, 'generation' => $project->generation]);
        $batch = GitHubPublication::factory()->create(['backlog_project_id' => $project->id, 'github_connection_id' => $github->id, 'status' => 'published']);
        $analysis = AiAnalysis::factory()->create(['backlog_project_id' => $project->id, 'ai_connection_id' => $ai->id]);
        $otherAnalysis = AiAnalysis::factory()->create(['backlog_project_id' => $other->id, 'ai_connection_id' => $ai->id]);
        $project->update(['github_connection_id' => $github->id, 'repository_id' => '123']);
        Http::preventStrayRequests();
        $this->actingAs($project->team->owner)->withSession(['team_id' => $project->team_id, 'project_id.'.$project->team_id => $project->id]);

        $this->deleteJson(route('projects.destroy', $project), $this->confirmation($project))->assertOk()->assertSessionMissing('project_id.'.$project->team_id);

        $this->assertModelMissing($project);
        $this->assertModelMissing($issue);
        $this->assertModelMissing($batch);
        $this->assertModelMissing($analysis);
        $this->assertModelExists($otherAnalysis);
        $this->assertModelExists($other);
        $this->assertModelExists($github);
        $this->assertModelExists($ai);
        $this->assertModelExists($project->team);
        $this->getJson(route('projects.index'))->assertOk()->assertJsonCount(1, 'projects')->assertJsonPath('project.id', $other->id);
        $this->getJson(route('workspace.show'), ['X-Project-ID' => $project->id])->assertNotFound();
        Http::assertNothingSent();
        (new AnalyzeProjectDocuments($analysis->id))->handle(app(AiPlanning::class));
        (new PublishGitHubIssues($batch->id))->handle(app(GitHubPublishing::class));
        (new SynchronizeGitHubIssues($project->id))->handle(app(GitHubSynchronization::class));
        Http::assertNothingSent();
    }

    public function test_last_project_deletion_returns_to_empty_customer_start(): void
    {
        $project = $this->project();
        $this->actingAs($project->team->owner)->withSession(['team_id' => $project->team_id]);
        $this->deleteJson(route('projects.destroy', $project), $this->confirmation($project))->assertOk();
        $this->getJson(route('projects.index'))->assertOk()->assertJsonCount(0, 'projects')->assertJsonPath('project', null);
    }

    public function test_confirmation_and_current_version_are_required(): void
    {
        $project = $this->project();
        $this->actingAs($project->team->owner);
        $this->deleteJson(route('projects.destroy', $project), [])->assertUnprocessable();
        $this->deleteJson(route('projects.destroy', $project), [...$this->confirmation($project), 'confirmation' => 'wrong'])->assertUnprocessable();
        $project->increment('version');
        $this->deleteJson(route('projects.destroy', $project), ['confirmation' => $project->name, 'version' => 1])->assertConflict();
        $this->assertModelExists($project);
    }

    #[DataProvider('restrictedRoles')]
    public function test_only_project_team_admins_can_delete(string $role): void
    {
        $project = $this->project();
        $user = User::factory()->create();
        $project->team->members()->attach($user, ['role' => $role]);
        $this->actingAs($user)->deleteJson(route('projects.destroy', $project), $this->confirmation($project))->assertForbidden();
        $this->assertModelExists($project);
    }

    public static function restrictedRoles(): array
    {
        return [['viewer'], ['editor'], ['reviewer']];
    }

    public function test_foreign_tenant_and_guest_cannot_delete_and_admin_can(): void
    {
        $project = $this->project();
        $this->deleteJson(route('projects.destroy', $project), $this->confirmation($project))->assertUnauthorized();
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->deleteJson(route('projects.destroy', $project), $this->confirmation($project))->assertNotFound();
        $project->team->members()->attach($outsider, ['role' => 'admin']);
        $this->deleteJson(route('projects.destroy', $project), $this->confirmation($project))->assertOk();
        $this->assertModelMissing($project);
    }

    public function test_publishing_and_sync_lock_prevents_deletion(): void
    {
        $project = $this->project();
        $this->actingAs($project->team->owner);
        $lock = Cache::lock('github-project-'.$project->id, 180);
        $lock->get();
        try {
            $this->deleteJson(route('projects.destroy', $project), $this->confirmation($project))->assertConflict();
            $this->assertModelExists($project);
        } finally {
            $lock->release();
        }
    }

    public function test_queued_publication_must_finish_before_deletion(): void
    {
        $project = $this->project();
        GitHubPublication::factory()->create(['backlog_project_id' => $project->id, 'status' => 'queued']);
        $this->actingAs($project->team->owner)->deleteJson(route('projects.destroy', $project), $this->confirmation($project))->assertConflict();
        $this->assertModelExists($project);
    }

    private function project(?Team $team = null): BacklogProject
    {
        $team ??= Team::factory()->create();

        return BacklogProject::factory()->create(['workspace' => (string) Str::uuid(), 'team_id' => $team->id, 'name' => 'Employee onboarding']);
    }

    private function confirmation(BacklogProject $project): array
    {
        return ['confirmation' => $project->name, 'version' => $project->version];
    }
}
