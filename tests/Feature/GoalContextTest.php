<?php

namespace Tests\Feature;

use App\Ai\Agents\DocumentPlanner;
use App\Models\AiAnalysis;
use App\Models\AiConnection;
use App\Models\BacklogProject;
use App\Models\Team;
use App\Services\AiPlanning;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class GoalContextTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_ai_uses_only_immediate_parent_brief_not_parent_description_or_grandparent_context(): void
    {
        $project = $this->fixture();
        Queue::fake();
        $connection = AiConnection::factory()->create(['team_id' => $project->team_id]);
        DocumentPlanner::fake([['summary' => 'No further evidence is available.', 'nodes' => [], 'questions' => []]])->preventStrayPrompts();
        $runId = $this->postJson(route('ai.analyze', $project), ['version' => $project->version, 'connection_id' => $connection->id, 'parent_node_id' => 'leaf', 'document_ids' => ['brief-1'], 'consent' => true])->assertAccepted()->json('id');
        $run = AiAnalysis::findOrFail($runId);
        $this->assertSame(['id', 'title', 'supports_parent', 'revision'], array_keys($run->input['direction']));
        $this->assertSame('middle', $run->input['direction']['id']);
        $this->assertSame('Keep the welcome pack useful before arrival.', $run->input['direction']['supports_parent']);
        $this->assertSame(['id', 'title', 'description', 'supports_parent', 'revision'], array_keys($run->input['parent']));
        app(AiPlanning::class)->analyze($runId);
        $this->assertSame('ready', $run->fresh()->status);
        DocumentPlanner::assertPrompted(function ($prompt): bool {
            return str_contains($prompt->prompt, 'Keep the welcome pack useful before arrival.')
                && str_contains($prompt->prompt, 'Prepare the contents of the welcome pack.')
                && ! str_contains($prompt->prompt, 'SECRET_PARENT_DESCRIPTION')
                && ! str_contains($prompt->prompt, 'SECRET_GRANDPARENT_BRIEF')
                && ! str_contains($prompt->prompt, 'SECRET_PARENT_EVIDENCE')
                && ! str_contains($prompt->prompt, 'SECRET_PROJECT_DESCRIPTION')
                && ! str_contains($prompt->prompt, 'Unselected source');
        });
    }

    public function test_editing_direction_brief_invalidates_affected_branch_and_preserves_independent_goal(): void
    {
        $project = $this->fixture();
        $other = $project->state['nodes']['other'];
        $response = $this->command($project, 'edit', ['node_id' => 'middle', 'title' => 'Welcome pack', 'description' => 'SECRET_PARENT_DESCRIPTION', 'supports_parent' => 'Focus the welcome pack on the first week.'])->assertOk();
        $response->assertJsonPath('state.nodes.middle.supports_parent', 'Focus the welcome pack on the first week.');
        $response->assertJsonPath('state.nodes.middle.status', 'needs_review')->assertJsonPath('state.nodes.leaf.status', 'needs_review');
        $this->assertSame($other, $response->json('state.nodes.other'));
        $this->getJson(route('workspace.show'))->assertOk()->assertJsonPath('state.nodes.middle.supports_parent', 'Focus the welcome pack on the first week.');
        $this->command($project->refresh(), 'edit', ['node_id' => 'middle', 'title' => 'Welcome pack', 'description' => 'Description', 'supports_parent' => str_repeat('x', 1001)])->assertUnprocessable();
    }

    public function test_missing_direction_brief_blocks_ai_without_sending_documents(): void
    {
        $project = $this->fixture();
        $state = $project->state;
        $state['nodes']['middle']['supports_parent'] = '';
        $project->update(['state' => $state]);
        Queue::fake();
        $connection = AiConnection::factory()->create(['team_id' => $project->team_id]);
        $this->postJson(route('ai.analyze', $project), ['version' => $project->version, 'connection_id' => $connection->id, 'parent_node_id' => 'leaf', 'document_ids' => ['brief-1'], 'consent' => true])->assertUnprocessable();
        $this->assertDatabaseCount('ai_analyses', 0);
        Queue::assertNothingPushed();
    }

    public function test_copying_selected_parent_attachments_to_task_is_additive_version_pinned_and_independent(): void
    {
        $project = $this->fixture();
        $state = $project->state;
        $state['nodes']['leaf']['type'] = 'task';
        $project->update(['state' => $state]);
        $before = $project->state['nodes']['middle'];
        $response = $this->command($project, 'copy_parent_documents', ['node_id' => 'leaf', 'document_ids' => ['brief-2']])->assertOk();
        $response->assertJsonPath('state.nodes.leaf.document_ids', ['brief-1', 'brief-2'])->assertJsonCount(3, 'state.documents');
        $this->assertSame($before, $response->json('state.nodes.middle'));
        $last = array_slice($response->json('state.history'), -1)[0];
        $this->assertSame('middle', $last['source_parent_id']);
        $this->assertSame(['brief-2'], $last['copied_document_ids']);
        $project->refresh();
        $this->command($project, 'attach_documents', ['node_id' => 'middle', 'document_ids' => []])->assertOk()->assertJsonPath('state.nodes.leaf.document_ids', ['brief-1', 'brief-2']);
        $project->refresh();
        $this->command($project, 'attach_documents', ['node_id' => 'leaf', 'document_ids' => ['brief-2']])->assertOk()->assertJsonPath('state.nodes.middle.document_ids', []);
    }

    public function test_copy_rejects_grandparent_unattached_and_root_requests(): void
    {
        $project = $this->fixture();
        $this->command($project, 'copy_parent_documents', ['node_id' => 'leaf', 'document_ids' => ['grandparent-doc']])->assertUnprocessable();
        $this->command($project, 'copy_parent_documents', ['node_id' => 'leaf', 'document_ids' => ['unknown']])->assertUnprocessable();
        $this->command($project, 'copy_parent_documents', ['node_id' => 'root', 'document_ids' => ['brief-1']])->assertUnprocessable();
        $this->command($project, 'copy_parent_documents', ['node_id' => 'leaf', 'document_ids' => []])->assertUnprocessable();
        $this->assertSame(['brief-1'], $project->refresh()->state['nodes']['leaf']['document_ids']);
    }

    private function fixture(): BacklogProject
    {
        $team = Team::factory()->create();
        $nodes = [];
        foreach (['root' => null, 'middle' => 'root', 'leaf' => 'middle', 'other' => null] as $id => $parent) {
            $nodes[$id] = ['id' => $id, 'parent_id' => $parent, 'type' => 'goal', 'title' => $id, 'description' => 'Goal description', 'supports_parent' => 'A short direction.', 'status' => 'approved', 'breakdown' => in_array($id, ['root', 'middle']) ? 'approved' : 'none', 'revision' => 1, 'published_revision' => null, 'completed_revision' => null, 'completed_at' => null, 'completed_by' => null, 'criteria' => [], 'assumptions' => [], 'dependencies' => [], 'evidence' => [], 'document_ids' => []];
        }
        $nodes['root']['supports_parent'] = 'SECRET_GRANDPARENT_BRIEF';
        $nodes['root']['document_ids'] = ['grandparent-doc'];
        $nodes['middle']['description'] = 'SECRET_PARENT_DESCRIPTION';
        $nodes['middle']['supports_parent'] = 'Keep the welcome pack useful before arrival.';
        $nodes['middle']['evidence'] = [['document_id' => 'brief-2', 'line' => 1, 'quote' => 'SECRET_PARENT_EVIDENCE']];
        $nodes['middle']['document_ids'] = ['brief-1', 'brief-2'];
        $nodes['leaf']['description'] = 'Prepare the contents of the welcome pack.';
        $nodes['leaf']['document_ids'] = ['brief-1'];
        $documents = [];
        foreach (['brief-1' => 'New starters need a welcome pack.', 'brief-2' => 'Unselected source', 'grandparent-doc' => 'Grandparent material'] as $id => $content) {
            $documents[] = ['id' => $id, 'name' => $id.'.txt', 'version' => 1, 'previous_id' => null, 'content' => $content, 'created_at' => now()->toIso8601String()];
        }
        $project = BacklogProject::factory()->create(['team_id' => $team->id, 'name' => 'Onboarding', 'description' => 'SECRET_PROJECT_DESCRIPTION', 'state' => ['nodes' => $nodes, 'documents' => $documents, 'decisions' => [], 'history' => [], 'batches' => [], 'mode' => 'ai-assisted']]);
        $this->actingAs($team->owner)->withSession(['team_id' => $team->id, 'project_id.'.$team->id => $project->id]);

        return $project;
    }

    private function command(BacklogProject $project, string $action, array $extra): TestResponse
    {
        return $this->postJson(route('workspace.commands'), ['version' => $project->version, 'action' => $action, ...$extra], ['X-Team-ID' => $project->team_id, 'X-Project-ID' => $project->id]);
    }
}
