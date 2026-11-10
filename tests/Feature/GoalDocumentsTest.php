<?php

namespace Tests\Feature;

use App\Models\AiConnection;
use App\Models\BacklogProject;
use App\Models\Team;
use App\Models\User;
use App\Services\BacklogWorkflow;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class GoalDocumentsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_pasted_document_attaches_only_to_selected_goal_and_is_persisted(): void
    {
        $project = $this->fixture();
        $before = $project->state['nodes']['resolution'];
        $response = $this->command($project, 'document', ['node_id' => 'requests', 'name' => 'Request policy.txt', 'content' => 'Only employees may submit requests.'])->assertOk();
        $document = array_slice($response->json('state.documents'), -1)[0];
        $response->assertJsonPath('state.nodes.requests.document_ids', [$document['id']]);
        $response->assertJsonPath('state.nodes.requests.status', 'needs_review');
        $response->assertJsonPath('state.nodes.identity.status', 'needs_review');
        $this->assertSame($before, $response->json('state.nodes.resolution'));
        $this->getJson(route('workspace.show'))->assertOk()->assertJsonPath('state.nodes.requests.document_ids', [$document['id']]);
    }

    public function test_file_upload_attaches_to_goal_without_a_second_request(): void
    {
        $project = $this->fixture();
        $response = $this->postJson(route('workspace.documents'), ['version' => $project->version, 'node_id' => 'resolution', 'file' => UploadedFile::fake()->createWithContent('Staff notes.md', '# Staff notes')])->assertOk();
        $document = array_slice($response->json('state.documents'), -1)[0];
        $response->assertJsonPath('state.nodes.resolution.document_ids', [$document['id']]);
        $this->assertSame('Staff notes.md', $document['name']);
        $response->assertJsonPath('state.nodes.requests.document_ids', []);
    }

    public function test_existing_document_can_be_shared_and_detached_without_removing_source_evidence(): void
    {
        $project = $this->fixture();
        $this->command($project, 'attach_documents', ['node_id' => 'requests', 'document_ids' => ['brief-v1']])->assertOk();
        $project->refresh();
        $this->command($project, 'attach_documents', ['node_id' => 'resolution', 'document_ids' => ['brief-v1']])->assertOk();
        $project->refresh();
        $before = $project->state['nodes']['resolution'];
        $evidence = $project->state['nodes']['requests']['evidence'];
        $this->command($project, 'attach_documents', ['node_id' => 'requests', 'document_ids' => []])->assertOk()->assertJsonPath('state.nodes.requests.document_ids', [])->assertJsonCount(1, 'state.documents');
        $this->assertSame($evidence, $project->refresh()->state['nodes']['requests']['evidence']);
        $this->assertSame($before, $project->state['nodes']['resolution']);
    }

    public function test_source_revision_keeps_old_attachment_and_evidence_and_marks_affected_goal_for_review(): void
    {
        $project = $this->fixture();
        $this->command($project, 'attach_documents', ['node_id' => 'requests', 'document_ids' => ['brief-v1']])->assertOk();
        $project->refresh();
        $before = $project->state['nodes']['requests']['revision'];
        $response = $this->command($project, 'document', ['document_id' => 'brief-v1', 'name' => 'Support portal brief.md', 'content' => 'Updated request requirements.'])->assertOk();
        $response->assertJsonPath('state.nodes.requests.document_ids', ['brief-v1']);
        $response->assertJsonPath('state.nodes.requests.revision', $before + 1);
        $response->assertJsonCount(2, 'state.documents');
        $this->assertSame('brief-v1', $response->json('state.nodes.requests.evidence.0.document_id'));
        $latest = array_slice($response->json('state.documents'), -1)[0];
        $this->assertSame(2, $latest['version']);
        $this->assertSame('brief-v1', $latest['previous_id']);
    }

    public function test_replacing_document_directly_on_goal_attaches_new_version_and_keeps_old_evidence(): void
    {
        $project = $this->fixture();
        $this->command($project, 'attach_documents', ['node_id' => 'requests', 'document_ids' => ['brief-v1']])->assertOk();
        $project->refresh();
        $revision = $project->state['nodes']['requests']['revision'];
        $response = $this->command($project, 'document', ['node_id' => 'requests', 'document_id' => 'brief-v1', 'name' => 'Updated brief.md', 'content' => 'Updated requirements.'])->assertOk();
        $latest = array_slice($response->json('state.documents'), -1)[0];
        $response->assertJsonPath('state.nodes.requests.document_ids', [$latest['id']])->assertJsonPath('state.nodes.requests.revision', $revision + 1)->assertJsonPath('state.nodes.requests.evidence.0.document_id', 'brief-v1');
    }

    public function test_invalid_node_foreign_document_duplicate_and_stale_requests_cannot_change_attachments(): void
    {
        $project = $this->fixture();
        $this->command($project, 'attach_documents', ['node_id' => 'requests', 'document_ids' => ['not-in-project']])->assertUnprocessable();
        $this->command($project, 'attach_documents', ['node_id' => 'nonexistent', 'document_ids' => ['brief-v1']])->assertUnprocessable();
        $this->command($project, 'attach_documents', ['node_id' => 'requests', 'document_ids' => ['brief-v1', 'brief-v1']])->assertUnprocessable();
        $this->command($project, 'document', ['node_id' => 'nonexistent', 'name' => 'Invalid.txt', 'content' => 'Do not save.'])->assertUnprocessable();
        $this->command($project, 'attach_documents', ['node_id' => 'requests', 'document_ids' => []])->assertOk();
        $this->command($project, 'attach_documents', ['node_id' => 'requests', 'document_ids' => ['brief-v1']])->assertConflict();
        $this->assertCount(1, $project->refresh()->state['documents']);
        $this->assertSame([], $project->state['nodes']['requests']['document_ids']);
    }

    public function test_viewer_and_foreign_tenant_cannot_attach_documents(): void
    {
        $project = $this->fixture();
        $user = User::factory()->create();
        $project->team->members()->attach($user, ['role' => 'viewer']);
        $this->actingAs($user);
        $this->command($project, 'attach_documents', ['node_id' => 'requests', 'document_ids' => ['brief-v1']])->assertForbidden();
        $foreign = Team::factory()->create();
        $this->actingAs($foreign->owner);
        $this->command($project, 'attach_documents', ['node_id' => 'requests', 'document_ids' => ['brief-v1']])->assertNotFound();
    }

    public function test_ai_breakdown_rejects_unattached_project_documents(): void
    {
        $project = $this->fixture();
        Queue::fake();
        $connection = AiConnection::factory()->create(['team_id' => $project->team_id]);
        $this->postJson(route('ai.analyze', $project), ['version' => $project->version, 'connection_id' => $connection->id, 'parent_node_id' => 'resolution', 'document_ids' => ['brief-v1'], 'consent' => true])->assertUnprocessable();
        $this->assertDatabaseCount('ai_analyses', 0);
        Queue::assertNothingPushed();
    }

    private function fixture(): BacklogProject
    {
        $team = Team::factory()->create();
        $this->actingAs($team->owner)->withSession(['team_id' => $team->id]);
        $project = app(BacklogWorkflow::class)->reset($team, $team->owner, null);
        $this->withSession(['project_id.'.$team->id => $project->id]);

        return $project;
    }

    private function command(BacklogProject $project, string $action, array $extra): TestResponse
    {
        return $this->postJson(route('workspace.commands'), ['version' => $project->version, 'action' => $action, ...$extra], ['X-Team-ID' => $project->team_id, 'X-Project-ID' => $project->id]);
    }
}
