<?php

namespace Tests\Feature;

use App\Models\BacklogProject;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BacklogWorkflowTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_reading_an_empty_workspace_does_not_create_records(): void
    {
        $this->getJson(route('workspace.show'))->assertOk()->assertJsonPath('state', null);

        $this->assertDatabaseCount('backlog_projects', 0);
    }

    public function test_example_is_persisted_with_a_blocked_and_an_independent_branch(): void
    {
        $this->postJson(route('workspace.reset'), ['confirmation' => 'RESET'])
            ->assertOk()->assertJsonPath('state.nodes.root.parent_id', null)
            ->assertJsonPath('state.nodes.queue.status', 'approved')
            ->assertJsonPath('state.decisions.intake-policy.answer', null);

        $this->getJson(route('workspace.show'))->assertJsonPath('state.nodes.root.title', 'Launch a customer support portal');
        $this->assertDatabaseCount('backlog_projects', 1);
    }

    public function test_main_goal_and_each_breakdown_require_human_approval(): void
    {
        $this->startFresh();
        $this->command('propose_root')->assertOk();
        $this->command('expand', ['node_id' => 'root'])->assertUnprocessable()->assertJsonValidationErrors('workflow');
        $this->command('approve', ['node_id' => 'root'])->assertOk();
        $this->command('expand', ['node_id' => 'root'])->assertOk()->assertJsonPath('state.nodes.requests.status', 'proposed');
        $this->command('expand', ['node_id' => 'requests'])->assertUnprocessable();
        $this->command('approve_breakdown', ['node_id' => 'root'])->assertOk();
        $this->command('expand', ['node_id' => 'requests'])->assertOk()->assertJsonPath('state.nodes.intake.parent_id', 'requests');

        $this->assertSame(5, count(BacklogProject::first()->state['nodes']));
    }

    public function test_duplicate_main_goals_and_task_decomposition_are_rejected(): void
    {
        $this->loadExample();

        $this->command('propose_root')->assertUnprocessable();
        $this->command('expand', ['node_id' => 'queue'])->assertUnprocessable();

        $this->assertSame(11, count(BacklogProject::first()->state['nodes']));
    }

    public function test_unresolved_decisions_block_only_the_affected_branch(): void
    {
        $this->loadExample();

        $this->command('approve', ['node_id' => 'attachments'])->assertUnprocessable();
        $this->command('approve_breakdown', ['node_id' => 'requests'])->assertUnprocessable();
        $this->command('approve', ['node_id' => 'reply'])->assertOk()->assertJsonPath('state.nodes.reply.status', 'approved');

        $this->assertSame('proposed', BacklogProject::first()->state['nodes']['attachments']['status']);
    }

    public function test_human_can_resolve_a_question_and_approve_the_branch(): void
    {
        $this->loadExample();

        $this->command('resolve', ['node_id' => 'intake', 'answer' => 'PNG and JPEG, maximum 5 MB.'])->assertOk()
            ->assertJsonPath('state.decisions.intake-policy.resolved_by', 'Local reviewer');
        $this->command('approve_breakdown', ['node_id' => 'requests'])->assertOk();
        $this->command('approve_breakdown', ['node_id' => 'intake'])->assertOk()
            ->assertJsonPath('state.nodes.attachments.status', 'approved');

        $this->assertSame('PNG and JPEG, maximum 5 MB.', BacklogProject::first()->state['decisions']['intake-policy']['answer']);
    }

    public function test_editing_invalidates_descendants_but_keeps_unrelated_approvals(): void
    {
        $this->loadExample();

        $this->command('edit', ['node_id' => 'requests', 'title' => 'Submit private support requests', 'description' => 'Updated request scope.'])
            ->assertOk()->assertJsonPath('state.nodes.requests.revision', 2)
            ->assertJsonPath('state.nodes.identity.status', 'needs_review')
            ->assertJsonPath('state.nodes.login.status', 'needs_review')
            ->assertJsonPath('state.nodes.queue.status', 'approved');

        $history = BacklogProject::first()->state['history'];
        $this->assertSame('edited', $history[array_key_last($history)]['action']);
        $this->assertSame(2, $history[array_key_last($history)]['revision']);
    }

    public function test_rejected_proposals_need_editing_before_approval(): void
    {
        $this->loadExample();

        $this->command('reject', ['node_id' => 'reply'])->assertOk();
        $this->command('approve', ['node_id' => 'reply'])->assertUnprocessable();
        $this->command('edit', ['node_id' => 'reply', 'title' => 'Reply and resolve', 'description' => 'A revised proposal.'])->assertOk();
        $this->command('approve', ['node_id' => 'reply'])->assertOk();

        $this->assertSame('approved', BacklogProject::first()->state['nodes']['reply']['status']);
    }

    public function test_dependency_cycles_are_rejected_without_mutating_saved_state(): void
    {
        $this->loadExample();
        $version = BacklogProject::first()->version;

        $this->command('edit', ['node_id' => 'queue', 'title' => 'Staff queue', 'description' => 'Queue.', 'dependencies' => ['reply']])
            ->assertUnprocessable()->assertJsonValidationErrors('workflow');

        $this->assertSame($version, BacklogProject::first()->version);
        $this->assertSame([], BacklogProject::first()->state['nodes']['queue']['dependencies']);
    }

    public function test_text_sources_are_versioned_and_source_changes_invalidate_approvals(): void
    {
        $this->loadExample();
        $original = BacklogProject::first()->state['documents'][0]['content'];

        $this->command('document', ['document_id' => 'brief-v1', 'name' => 'Revised brief.md', 'content' => 'A revised scope.'])
            ->assertOk()->assertJsonPath('state.documents.1.version', 2)
            ->assertJsonPath('state.nodes.root.status', 'needs_review');

        $this->assertSame($original, BacklogProject::first()->state['documents'][0]['content']);
        $this->assertSame('brief-v1', BacklogProject::first()->state['nodes']['root']['evidence'][0]['document_id']);
    }

    public function test_new_documents_do_not_claim_scripted_analysis_or_invalidate_existing_work(): void
    {
        $this->loadExample();

        $this->command('document', ['name' => 'Another source.md', 'content' => '<script>alert("test")</script>'])
            ->assertOk()->assertJsonPath('state.nodes.root.status', 'approved');

        $this->assertSame('<script>alert("test")</script>', BacklogProject::first()->state['documents'][1]['content']);
    }

    public function test_upload_accepts_utf8_markdown_and_rejects_wrong_extensions_binary_and_empty_text(): void
    {
        $this->loadExample();
        $this->upload('requirements.md', '# Requirements')->assertOk();
        $this->upload('requirements.php', '<?php echo 1;')->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->upload('binary.txt', "binary\0text")->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->upload('empty.txt', '   ')->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertCount(2, BacklogProject::first()->state['documents']);
    }

    public function test_publication_includes_goal_ancestors_and_is_repeat_safe_without_network_requests(): void
    {
        Http::preventStrayRequests();
        $this->loadExample();

        $response = $this->command('prepare_batch', ['node_ids' => ['queue']])->assertOk();
        $batch = $response->json('state.batches.0');
        $this->assertSame(['queue', 'resolution', 'root'], array_column($batch['items'], 'node_id'));
        $this->command('publish', ['batch_id' => $batch['id']])->assertOk()->assertJsonPath('state.batches.0.status', 'published');
        $this->command('publish', ['batch_id' => $batch['id']])->assertOk()->assertJsonCount(1, 'state.batches');

        $history = BacklogProject::first()->state['history'];
        $this->assertCount(1, array_filter($history, fn (array $event): bool => $event['action'] === 'simulation_published'));
        Http::assertNothingSent();
    }

    public function test_unapproved_publication_and_changed_previews_are_blocked(): void
    {
        $this->loadExample();
        $this->command('prepare_batch', ['node_ids' => ['reply']])->assertUnprocessable();
        $batch = $this->command('prepare_batch', ['node_ids' => ['queue']])->json('state.batches.0');
        $this->command('edit', ['node_id' => 'queue', 'title' => 'Updated queue', 'description' => 'A changed scope.'])->assertOk();

        $this->command('publish', ['batch_id' => $batch['id']])->assertUnprocessable();

        $this->assertSame('stale', BacklogProject::first()->state['batches'][0]['status']);
        $this->assertNull(BacklogProject::first()->state['nodes']['queue']['published_revision']);
    }

    public function test_stale_commands_and_unconfirmed_resets_cannot_overwrite_saved_work(): void
    {
        $this->loadExample();
        $this->command('approve', ['node_id' => 'reply'])->assertOk();

        $this->postJson(route('workspace.commands'), ['version' => 1, 'action' => 'reject', 'node_id' => 'reply'])->assertConflict();
        $this->postJson(route('workspace.reset'), ['version' => 2])->assertUnprocessable()->assertJsonValidationErrors('confirmation');
        $this->postJson(route('workspace.reset'), ['version' => 1, 'confirmation' => 'RESET'])->assertConflict();

        $this->assertSame('approved', BacklogProject::first()->state['nodes']['reply']['status']);
    }

    public function test_unknown_nodes_and_missing_command_fields_return_validation_errors(): void
    {
        $this->loadExample();

        $this->command('approve', ['node_id' => 'missing'])->assertUnprocessable()->assertJsonValidationErrors('workflow');
        $this->command('edit', ['node_id' => 'queue'])->assertUnprocessable()->assertJsonValidationErrors(['title', 'description']);
        $this->command('resolve', ['node_id' => 'intake'])->assertUnprocessable()->assertJsonValidationErrors('answer');
        $this->command('prepare_batch', ['node_ids' => []])->assertUnprocessable()->assertJsonValidationErrors('node_ids');
    }

    public function test_a_stale_ancestor_blocks_breakdown_approval_even_when_child_goal_is_reapproved(): void
    {
        $this->loadExample();
        $this->command('edit', ['node_id' => 'root', 'title' => 'Revised main goal', 'description' => 'Updated overall scope.'])->assertOk();
        $this->command('approve', ['node_id' => 'root'])->assertOk();

        $this->command('approve_breakdown', ['node_id' => 'resolution'])->assertUnprocessable();

        $this->assertSame('needs_review', BacklogProject::first()->state['nodes']['queue']['status']);
    }

    public function test_unrelated_branch_edits_preserve_an_independent_publication_preview(): void
    {
        $this->loadExample();
        $batch = $this->command('prepare_batch', ['node_ids' => ['queue']])->json('state.batches.0');
        $this->command('edit', ['node_id' => 'requests', 'title' => 'Revised intake', 'description' => 'A separate branch.'])->assertOk();

        $this->command('publish', ['batch_id' => $batch['id']])->assertOk();

        $this->assertSame('published', BacklogProject::first()->state['batches'][0]['status']);
    }

    public function test_tasks_without_acceptance_criteria_cannot_be_approved(): void
    {
        $this->loadExample();
        $this->command('edit', ['node_id' => 'queue', 'title' => 'Queue', 'description' => 'Queue scope.', 'criteria' => []])->assertOk();

        $this->command('approve', ['node_id' => 'queue'])->assertUnprocessable();

        $this->assertSame('needs_review', BacklogProject::first()->state['nodes']['queue']['status']);
    }

    public function test_approval_snapshots_preserve_the_content_reviewed_before_an_edit(): void
    {
        $this->loadExample();

        $this->command('edit', ['node_id' => 'queue', 'title' => 'Changed queue', 'description' => 'A new interpretation.'])->assertOk();

        $approvals = array_values(array_filter(BacklogProject::first()->state['history'], fn (array $event): bool => $event['action'] === 'approved' && $event['node_id'] === 'queue'));
        $this->assertSame('Build the staff request queue', $approvals[0]['snapshot']['title']);
        $this->assertSame(1, $approvals[0]['revision']);
    }

    public function test_ancestor_changes_do_not_restore_rejected_descendant_proposals(): void
    {
        $this->loadExample();
        $this->command('reject', ['node_id' => 'reply'])->assertOk();

        $this->command('edit', ['node_id' => 'resolution', 'title' => 'Updated resolution goal', 'description' => 'A changed branch.'])->assertOk();

        $this->assertSame('rejected', BacklogProject::first()->state['nodes']['reply']['status']);
    }

    public function test_users_can_add_multiple_independent_top_level_goals(): void
    {
        $this->loadExample();
        $this->command('add_goal', ['title' => 'Launch a mobile app', 'description' => 'Help customers manage requests on mobile.'])->assertOk();
        $this->command('add_goal', ['title' => 'Build reporting', 'description' => 'Measure support outcomes.'])->assertOk();

        $state = $this->getJson(route('workspace.show'))->assertOk()->json('state');

        $roots = array_values(array_filter($state['nodes'], fn (array $node): bool => $node['parent_id'] === null));
        $this->assertCount(3, $roots);
        $this->assertSame('Launch a mobile app', $roots[1]['title']);
        $this->assertSame('proposed', $roots[1]['status']);
        $this->assertSame([], $roots[1]['evidence']);
        $this->assertNotSame($roots[1]['id'], $roots[2]['id']);
    }

    public function test_new_goals_have_isolated_breakdowns_dependencies_and_approval_invalidation(): void
    {
        $this->startFresh();
        $this->command('add_goal', ['title' => 'Mobile app', 'description' => 'Deliver mobile support.'])->assertOk();
        $this->command('add_goal', ['title' => 'Reporting', 'description' => 'Measure support.'])->assertOk();
        $roots = array_keys(BacklogProject::first()->state['nodes']);
        foreach ($roots as $root) {
            $this->command('expand', ['node_id' => $root])->assertUnprocessable();
            $this->command('approve', ['node_id' => $root])->assertOk();
            $this->command('expand', ['node_id' => $root])->assertOk();
            $this->command('approve_breakdown', ['node_id' => $root])->assertOk();
            $this->command('expand', ['node_id' => $root.':custom_delivery'])->assertOk();
        }
        $this->command('edit', ['node_id' => $roots[0], 'title' => 'Revised mobile app', 'description' => 'Updated mobile scope.'])->assertOk();

        $state = BacklogProject::first()->state;
        $this->assertSame('needs_review', $state['nodes'][$roots[0].':custom_delivery']['status']);
        $this->assertSame('approved', $state['nodes'][$roots[1].':custom_delivery']['status']);
        $this->assertSame([$roots[1].':custom_implement'], $state['nodes'][$roots[1].':custom_verify']['dependencies']);
        $this->command('approve_breakdown', ['node_id' => $roots[1].':custom_delivery'])->assertOk();
        $response = $this->command('prepare_batch', ['node_ids' => [$roots[1].':custom_verify']])->assertOk();
        $this->assertSame([$roots[1].':custom_verify', $roots[1].':custom_delivery', $roots[1]], array_column($response->json('state.batches.0.items'), 'node_id'));
    }

    public function test_new_goals_require_a_title_and_description(): void
    {
        $this->startFresh();

        $this->command('add_goal')->assertUnprocessable()->assertJsonValidationErrors(['title', 'description']);

        $this->assertSame([], BacklogProject::first()->state['nodes']);
    }

    public function test_parent_completion_requires_every_active_child_and_is_explicit(): void
    {
        $this->loadExample();
        $this->command('approve', ['node_id' => 'reply'])->assertOk();
        $this->command('complete', ['node_id' => 'resolution'])->assertUnprocessable();
        $this->command('complete', ['node_id' => 'reply'])->assertUnprocessable();
        $this->command('complete', ['node_id' => 'queue'])->assertOk();
        $this->command('complete', ['node_id' => 'resolution'])->assertUnprocessable();
        $this->command('complete', ['node_id' => 'reply'])->assertOk();
        $this->assertNull(BacklogProject::first()->state['nodes']['resolution']['completed_revision'] ?? null);

        $this->command('complete', ['node_id' => 'resolution'])->assertOk()
            ->assertJsonPath('state.nodes.resolution.completed_revision', 1)
            ->assertJsonPath('state.nodes.resolution.completed_by', 'Local reviewer');

        $this->getJson(route('workspace.show'))->assertJsonPath('state.nodes.resolution.completed_revision', 1);
        $this->assertSame('approved', BacklogProject::first()->state['nodes']['resolution']['status']);
    }

    public function test_reopening_a_prerequisite_reopens_completed_dependents_and_parents(): void
    {
        $this->loadExample();
        $this->command('approve', ['node_id' => 'reply'])->assertOk();
        foreach (['queue', 'reply', 'resolution'] as $id) {
            $this->command('complete', ['node_id' => $id])->assertOk();
        }

        $this->command('reopen', ['node_id' => 'queue'])->assertOk();

        $state = BacklogProject::first()->state;
        foreach (['queue', 'reply', 'resolution'] as $id) {
            $this->assertNull($state['nodes'][$id]['completed_revision']);
            $this->assertSame('approved', $state['nodes'][$id]['status']);
        }
        $this->assertSame('approved', $state['nodes']['requests']['status']);
    }

    public function test_editing_completed_work_reopens_its_completed_dependents_and_parent(): void
    {
        $this->loadExample();
        $this->command('approve', ['node_id' => 'reply'])->assertOk();
        foreach (['queue', 'reply', 'resolution'] as $id) {
            $this->command('complete', ['node_id' => $id])->assertOk();
        }

        $this->command('edit', ['node_id' => 'queue', 'title' => 'Updated queue', 'description' => 'A new scope.'])->assertOk();

        $state = BacklogProject::first()->state;
        $this->assertSame('needs_review', $state['nodes']['queue']['status']);
        $this->assertNull($state['nodes']['reply']['completed_revision']);
        $this->assertNull($state['nodes']['resolution']['completed_revision']);
    }

    public function test_unapproved_blocked_and_undecomposed_nodes_cannot_be_completed(): void
    {
        $this->loadExample();
        $this->command('complete', ['node_id' => 'reply'])->assertUnprocessable();
        $this->command('complete', ['node_id' => 'intake'])->assertUnprocessable();
        $this->command('reopen', ['node_id' => 'queue'])->assertUnprocessable();
        $response = $this->command('add_goal', ['title' => 'Another goal', 'description' => 'An independent objective.'])->assertOk();
        $id = array_key_last($response->json('state.nodes'));
        $this->command('approve', ['node_id' => $id])->assertOk();

        $this->command('complete', ['node_id' => $id])->assertUnprocessable();

        $this->assertNull(BacklogProject::first()->state['nodes'][$id]['completed_revision']);
    }

    public function test_rejected_children_are_excluded_from_parent_completion(): void
    {
        $this->loadExample();
        $this->command('reject', ['node_id' => 'reply'])->assertOk();
        $this->command('complete', ['node_id' => 'queue'])->assertOk();

        $this->command('complete', ['node_id' => 'resolution'])->assertOk();

        $this->assertSame(1, BacklogProject::first()->state['nodes']['resolution']['completed_revision']);
        $this->assertSame('rejected', BacklogProject::first()->state['nodes']['reply']['status']);
    }

    public function test_repeated_completion_preserves_a_single_completion_event(): void
    {
        $this->loadExample();
        $this->command('complete', ['node_id' => 'queue'])->assertOk();

        $this->command('complete', ['node_id' => 'queue'])->assertOk();

        $events = array_filter(BacklogProject::first()->state['history'], fn (array $event): bool => $event['action'] === 'completed' && $event['node_id'] === 'queue');
        $this->assertCount(1, $events);
    }

    public function test_changed_parent_blocks_child_and_group_approval_without_recording_approvals(): void
    {
        $this->loadExample();
        $this->command('edit', ['node_id' => 'resolution', 'title' => 'Changed staff workflow', 'description' => 'A revised parent goal.'])->assertOk();
        $before = BacklogProject::first();

        $this->command('approve', ['node_id' => 'reply'])->assertUnprocessable()->assertJsonValidationErrors('workflow');
        $this->command('approve_breakdown', ['node_id' => 'resolution'])->assertUnprocessable()->assertJsonValidationErrors('workflow');

        $after = BacklogProject::first();
        $this->assertSame($before->version, $after->version);
        $this->assertSame($before->state['history'], $after->state['history']);
        $this->assertSame('needs_review', $after->state['nodes']['reply']['status']);
        $this->assertSame('approved', $after->state['nodes']['requests']['status']);
    }

    public function test_approving_parent_unlocks_its_child_breakdown(): void
    {
        $this->loadExample();
        $this->command('edit', ['node_id' => 'resolution', 'title' => 'Changed staff workflow', 'description' => 'A revised parent goal.'])->assertOk();
        $this->command('approve', ['node_id' => 'resolution'])->assertOk();

        $this->command('approve_breakdown', ['node_id' => 'resolution'])->assertOk()
            ->assertJsonPath('state.nodes.queue.status', 'approved')
            ->assertJsonPath('state.nodes.reply.status', 'approved');

        $this->command('approve', ['node_id' => 'reply'])->assertOk();
    }

    public function test_rejected_parent_blocks_all_descendant_approvals(): void
    {
        $this->loadExample();
        $this->command('reject', ['node_id' => 'resolution'])->assertOk();

        $this->command('approve', ['node_id' => 'queue'])->assertUnprocessable();
        $this->command('approve', ['node_id' => 'reply'])->assertUnprocessable();
        $this->command('approve_breakdown', ['node_id' => 'resolution'])->assertUnprocessable();

        $state = BacklogProject::first()->state;
        $this->assertSame('rejected', $state['nodes']['resolution']['status']);
        $this->assertSame('needs_review', $state['nodes']['queue']['status']);
        $this->assertSame('needs_review', $state['nodes']['reply']['status']);
    }

    public function test_unapproved_parent_blocks_child_approval_even_if_children_already_exist(): void
    {
        $project = BacklogProject::factory()->create();
        $state = $project->state;
        $state['nodes'] = [
            'parent' => ['id' => 'parent', 'parent_id' => null, 'type' => 'goal', 'status' => 'proposed', 'breakdown' => 'proposed', 'revision' => 1],
            'child' => ['id' => 'child', 'parent_id' => 'parent', 'type' => 'task', 'status' => 'proposed', 'breakdown' => 'none', 'revision' => 1, 'criteria' => ['The behavior is verified.']],
        ];
        $project->update(['state' => $state]);

        $this->command('approve', ['node_id' => 'child'])->assertUnprocessable()->assertJsonValidationErrors('workflow');
        $this->command('approve_breakdown', ['node_id' => 'parent'])->assertUnprocessable()->assertJsonValidationErrors('workflow');

        $this->assertSame('proposed', $project->refresh()->state['nodes']['child']['status']);
        $this->assertSame([], $project->state['history']);
    }

    private function startFresh(): void
    {
        $this->postJson(route('workspace.reset'), ['confirmation' => 'RESET', 'example' => false])->assertOk();
    }

    private function loadExample(): void
    {
        $this->postJson(route('workspace.reset'), ['confirmation' => 'RESET'])->assertOk();
    }

    /** @param array<string, mixed> $payload */
    private function command(string $action, array $payload = []): TestResponse
    {
        return $this->postJson(route('workspace.commands'), ['action' => $action, 'version' => BacklogProject::first()->version, ...$payload]);
    }

    private function upload(string $name, string $content): TestResponse
    {
        return $this->post(route('workspace.documents'), [
            'version' => BacklogProject::first()->version,
            'file' => UploadedFile::fake()->createWithContent($name, $content),
        ], ['Accept' => 'application/json']);
    }
}
