<?php

namespace Tests\Feature;

use App\Ai\Agents\DocumentPlanner;
use App\Jobs\AnalyzeProjectDocuments;
use App\Models\AiAnalysis;
use App\Models\AiConnection;
use App\Models\BacklogProject;
use App\Models\Team;
use App\Models\User;
use App\Services\AiPlanning;
use App\Services\BacklogWorkflow;
use App\Services\DemoScenario;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AiPlanningTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_team_keys_are_encrypted_hidden_and_cannot_be_managed_by_other_tenants(): void
    {
        [$project] = $this->fixture();
        $this->putJson(route('ai.connection', $project), ['provider' => 'anthropic', 'model' => 'test-claude', 'api_key' => 'a-private-test-api-key'])->assertOk()->assertJsonMissingPath('api_key');
        $connection = AiConnection::where('provider', 'anthropic')->firstOrFail();
        $stored = DB::table('ai_connections')->where('id', $connection->id)->value('api_key');
        $this->assertNotSame('a-private-test-api-key', $stored);
        $this->assertSame('a-private-test-api-key', $connection->api_key);
        $this->getJson(route('ai.index', $project))->assertOk()->assertDontSee('a-private-test-api-key')->assertJsonMissingPath('connections.0.api_key');
        $foreign = BacklogProject::factory()->create(['workspace' => 'foreign-project']);
        $this->getJson(route('ai.index', $foreign))->assertNotFound();
        $this->deleteJson(route('ai.disconnect', [$foreign, $connection]))->assertNotFound();
    }

    public function test_analysis_requires_explicit_consent_and_project_owned_sources_and_connections(): void
    {
        [$project, $connection] = $this->fixture();
        $payload = $this->payload($project, $connection);
        $this->postJson(route('ai.analyze', $project), [...$payload, 'consent' => false])->assertUnprocessable();
        $this->postJson(route('ai.analyze', $project), [...$payload, 'document_ids' => ['other-project-doc']])->assertUnprocessable();
        $foreign = AiConnection::factory()->create();
        $this->postJson(route('ai.analyze', $project), [...$payload, 'connection_id' => $foreign->id])->assertNotFound();
        $this->postJson(route('ai.analyze', $project), [...$payload, 'version' => 100])->assertConflict();
        $this->assertDatabaseCount('ai_analyses', 0);
        Queue::assertNothingPushed();
    }

    public function test_verified_source_linked_goal_is_added_only_on_explicit_request_and_remains_unapproved(): void
    {
        [$project, $connection] = $this->fixture();
        DocumentPlanner::fake([$this->response()])->preventStrayPrompts();
        $run = $this->start($project, $connection);
        Queue::assertPushed(AnalyzeProjectDocuments::class, fn ($job) => $job->analysisId === $run->id);
        app(AiPlanning::class)->analyze($run->id);
        $this->assertSame('ready', $run->fresh()->status);
        $this->assertSame([], $project->fresh()->state['nodes']);
        DocumentPlanner::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'doc-v1') && ! str_contains($prompt->prompt, 'unselected secret'));
        $result = $this->postJson(route('ai.apply', [$project, $run]), ['confirmation' => 'ADD_DRAFTS'])->assertOk()->assertJsonCount(1, 'state.nodes');
        $node = array_values($result->json('state.nodes'))[0];
        $this->assertSame('proposed', $node['status']);
        $this->assertSame('doc-v1', $node['evidence'][0]['document_id']);
        $this->assertSame(2, $node['evidence'][0]['line']);
        $this->assertSame($run->id, $node['ai_analysis_id']);
        $this->assertSame(['doc-v1'], $node['document_ids']);
        $this->assertSame('applied', $run->fresh()->status);
        $this->postJson(route('ai.apply', [$project, $run]), ['confirmation' => 'ADD_DRAFTS'])->assertOk()->assertJsonCount(1, 'state.nodes');
    }

    public function test_goal_breakdown_retains_hierarchy_dependencies_and_unresolved_question_gates(): void
    {
        [$project, $connection] = $this->fixture();
        $workflow = app(BacklogWorkflow::class);
        $project = $workflow->command($project->team, $project->team->owner, ['version' => $project->version, 'action' => 'add_goal', 'title' => 'Welcome new starters', 'description' => 'Make day one welcoming', 'supports_parent' => 'Prepare new starters for day one.'], $project);
        $parent = array_key_first($project->state['nodes']);
        $this->postJson(route('ai.analyze', $project), [...$this->payload($project, $connection), 'parent_node_id' => $parent])->assertUnprocessable();
        $project = $workflow->command($project->team, $project->team->owner, ['version' => $project->version, 'action' => 'attach_documents', 'node_id' => $parent, 'document_ids' => ['doc-v1']], $project);
        $project = $workflow->command($project->team, $project->team->owner, ['version' => $project->version, 'action' => 'approve', 'node_id' => $parent], $project);
        $this->postJson(route('ai.analyze', $project), [...$this->payload($project, $connection), 'parent_node_id' => $parent, 'document_ids' => ['unselected']])->assertUnprocessable();
        $first = $this->response()['nodes'][0];
        $first['type'] = 'task';
        $first['criteria'] = ['Pack is ready before day one.'];
        $second = [...$first, 'key' => 'send', 'title' => 'Send the welcome pack', 'dependencies' => ['welcome']];
        $response = ['summary' => 'Two steps for review.', 'nodes' => [$first, $second], 'questions' => [['node_key' => 'welcome', 'question' => 'Who reviews the pack?', 'proposal' => 'HR reviews it.', 'rationale' => 'The owner is not specified.', 'alternatives' => ['Hiring manager reviews it.']]]];
        DocumentPlanner::fake([$response])->preventStrayPrompts();
        $run = $this->start($project, $connection, $parent);
        app(AiPlanning::class)->analyze($run->id);
        $result = $this->postJson(route('ai.apply', [$project, $run]), ['confirmation' => 'ADD_DRAFTS'])->assertOk();
        $state = $result->json('state');
        $children = array_values(array_filter($state['nodes'], fn ($node) => $node['parent_id'] === $parent));
        $this->assertCount(2, $children);
        $this->assertSame([$children[0]['id']], $children[1]['dependencies']);
        $this->assertSame('proposed', $state['nodes'][$parent]['breakdown']);
        $this->postJson(route('workspace.commands'), ['version' => $result->json('version'), 'action' => 'approve_breakdown', 'node_id' => $parent], ['X-Project-ID' => $project->id])->assertUnprocessable();
        $decision = array_values($state['decisions'])[0];
        $this->assertNull($decision['answer']);
        $this->assertSame($children[0]['id'], $decision['node_id']);
        $resolved = $this->postJson(route('workspace.commands'), ['version' => $result->json('version'), 'action' => 'resolve', 'node_id' => $children[0]['id'], 'answer' => 'HR reviews the pack.'], ['X-Project-ID' => $project->id])->assertOk();
        $this->postJson(route('workspace.commands'), ['version' => $resolved->json('version'), 'action' => 'approve_breakdown', 'node_id' => $parent], ['X-Project-ID' => $project->id])->assertOk();
    }

    #[DataProvider('invalidResponses')]
    public function test_invalid_sources_and_structures_never_become_drafts(string $defect): void
    {
        [$project, $connection] = $this->fixture();
        $response = $this->response();
        switch ($defect) {
            case 'quote': $response['nodes'][0]['evidence'][0]['quote'] = 'Invented fact';
                break;
            case 'document': $response['nodes'][0]['evidence'][0]['document_id'] = 'foreign';
                break;
            case 'line': $response['nodes'][0]['evidence'][0]['end_line'] = 200;
                break;
            case 'empty_evidence': $response['nodes'][0]['evidence'] = [];
                break;
            case 'root_task': $response['nodes'][0]['type'] = 'task';
                break;
            case 'dependency': $response['nodes'][0]['dependencies'] = ['foreign'];
                break;
            case 'question': $response['questions'] = [['node_key' => 'foreign', 'question' => 'Who?', 'proposal' => 'HR', 'rationale' => 'Unknown', 'alternatives' => []]];
                break;
        }
        DocumentPlanner::fake([$response])->preventStrayPrompts();
        $run = $this->start($project, $connection);
        app(AiPlanning::class)->analyze($run->id);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertNull($run->fresh()->result);
        $this->assertSame([], $project->fresh()->state['nodes']);
        $this->postJson(route('ai.apply', [$project, $run]), ['confirmation' => 'ADD_DRAFTS'])->assertUnprocessable();
    }

    public static function invalidResponses(): array
    {
        return array_map(fn ($value) => [$value], ['quote', 'document', 'line', 'empty_evidence', 'root_task', 'dependency', 'question']);
    }

    public function test_changed_project_and_reset_generation_prevent_stale_import(): void
    {
        [$project, $connection] = $this->fixture();
        DocumentPlanner::fake([$this->response()])->preventStrayPrompts();
        $run = $this->start($project, $connection);
        app(AiPlanning::class)->analyze($run->id);
        $project->increment('version');
        $this->postJson(route('ai.apply', [$project, $run]), ['confirmation' => 'ADD_DRAFTS'])->assertConflict();
        $project->update(['generation' => (string) Str::uuid()]);
        $this->postJson(route('ai.apply', [$project, $run]), ['confirmation' => 'ADD_DRAFTS'])->assertNotFound();
    }

    public function test_keys_removed_or_roles_revoked_before_processing_prevent_provider_calls(): void
    {
        [$project, $connection] = $this->fixture();
        DocumentPlanner::fake()->preventStrayPrompts();
        $run = $this->start($project, $connection);
        $this->deleteJson(route('ai.disconnect', [$project, $connection]))->assertOk();
        app(AiPlanning::class)->analyze($run->id);
        $this->assertSame('failed', $run->fresh()->status);
        DocumentPlanner::assertNeverPrompted();
    }

    public function test_duplicate_processing_and_concurrent_requests_do_not_repeat_provider_calls(): void
    {
        [$project, $connection] = $this->fixture();
        DocumentPlanner::fake([$this->response()])->preventStrayPrompts();
        $run = $this->start($project, $connection);
        $this->postJson(route('ai.analyze', $project), $this->payload($project, $connection))->assertUnprocessable();
        app(AiPlanning::class)->analyze($run->id);
        app(AiPlanning::class)->analyze($run->id);
        DocumentPlanner::assertPromptedTimes(1);
    }

    #[DataProvider('unprivilegedRoles')]
    public function test_role_permissions_apply_to_connections_and_analysis(string $role): void
    {
        [$project, $connection] = $this->fixture();
        $member = User::factory()->create();
        $project->team->members()->attach($member, ['role' => $role]);
        $this->actingAs($member);
        $this->putJson(route('ai.connection', $project), ['provider' => 'openai', 'model' => 'test', 'api_key' => 'secret-test-key'])->assertForbidden();
        $this->deleteJson(route('ai.disconnect', [$project, $connection]))->assertForbidden();
        if ($role === 'viewer') {
            $this->postJson(route('ai.analyze', $project), $this->payload($project, $connection))->assertForbidden();
        }
    }

    public static function unprivilegedRoles(): array
    {
        return [['viewer'], ['editor'], ['reviewer']];
    }

    #[DataProvider('providerEndpoints')]
    public function test_sdk_uses_only_the_selected_team_key_and_provider(string $provider, string $host, string $header, string $prefix): void
    {
        [$project, $connection] = $this->fixture();
        $connection->update(['provider' => $provider]);
        Http::fake(['*' => Http::response(['error' => ['message' => 'test-ai-key-never-real']], 401)]);
        $run = $this->start($project, $connection);
        app(AiPlanning::class)->analyze($run->id);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), $host) && $request->hasHeader($header, $prefix.'test-ai-key-never-real'));
        $this->assertSame('failed', $run->fresh()->status);
        $this->getJson(route('ai.index', $project))->assertOk()->assertDontSee('test-ai-key-never-real');
    }

    public static function providerEndpoints(): array
    {
        return [['openai', 'api.openai.com', 'Authorization', 'Bearer '], ['anthropic', 'api.anthropic.com', 'x-api-key', ''], ['gemini', 'generativelanguage.googleapis.com', 'x-goog-api-key', '']];
    }

    public function test_document_limits_and_processing_permission_changes_are_enforced(): void
    {
        [$project, $connection] = $this->fixture();
        $state = $project->state;
        $state['documents'][0]['content'] = str_repeat('a', 60001);
        $project->update(['state' => $state]);
        $this->postJson(route('ai.analyze', $project), $this->payload($project, $connection))->assertUnprocessable();
        $state['documents'][0]['content'] = 'A short document.';
        $project->update(['state' => $state]);
        $member = User::factory()->create();
        $project->team->members()->attach($member, ['role' => 'editor']);
        $this->actingAs($member);
        $run = $this->start($project, $connection);
        $project->team->members()->updateExistingPivot($member->id, ['role' => 'viewer']);
        DocumentPlanner::fake()->preventStrayPrompts();
        app(AiPlanning::class)->analyze($run->id);
        $this->assertSame('failed', $run->fresh()->status);
        DocumentPlanner::assertNeverPrompted();
    }

    public function test_cyclic_generated_dependencies_are_rejected(): void
    {
        [$project, $connection] = $this->fixture();
        $node = $this->response()['nodes'][0];
        $node['type'] = 'task';
        $node['criteria'] = ['Done'];
        $node['dependencies'] = ['second'];
        $other = [...$node, 'key' => 'second', 'dependencies' => ['welcome']];
        $run = AiAnalysis::factory()->create(['backlog_project_id' => $project->id, 'ai_connection_id' => $connection->id, 'parent_node_id' => 'parent', 'input' => ['documents' => $project->state['documents']]]);
        $this->expectException(ValidationException::class);
        app(AiPlanning::class)->validateResult($run, ['summary' => 'Invalid cycle', 'nodes' => [$node, $other], 'questions' => []]);
    }

    public function test_empty_supported_response_stays_readable_without_invented_proposals(): void
    {
        [$project, $connection] = $this->fixture();
        DocumentPlanner::fake([['summary' => 'These notes do not describe a project outcome. Please add a clearer brief.', 'nodes' => [], 'questions' => []]])->preventStrayPrompts();
        $run = $this->start($project, $connection);
        app(AiPlanning::class)->analyze($run->id);
        $this->assertSame('ready', $run->fresh()->status);
        $this->postJson(route('ai.apply', [$project, $run]), ['confirmation' => 'ADD_DRAFTS'])->assertUnprocessable();
        $this->assertSame([], $project->fresh()->state['nodes']);
    }

    private function fixture(): array
    {
        Queue::fake();
        Http::preventStrayRequests();
        $team = Team::factory()->create();
        $state = app(DemoScenario::class)->initial();
        $state['documents'] = [
            ['id' => 'doc-v1', 'name' => 'Onboarding.txt', 'version' => 1, 'previous_id' => null, 'content' => "Welcome plan\nEvery new starter must receive a welcome pack before day one.", 'created_at' => now()->toIso8601String()],
            ['id' => 'unselected', 'name' => 'Private.txt', 'version' => 1, 'previous_id' => null, 'content' => 'unselected secret', 'created_at' => now()->toIso8601String()],
        ];
        $project = BacklogProject::factory()->create(['team_id' => $team->id, 'state' => $state, 'version' => 1]);
        $connection = AiConnection::factory()->create(['team_id' => $team->id]);
        $this->actingAs($team->owner)->withSession(['team_id' => $team->id, 'project_id.'.$team->id => $project->id]);

        return [$project, $connection];
    }

    private function payload(BacklogProject $project, AiConnection $connection): array
    {
        return ['version' => $project->version, 'connection_id' => $connection->id, 'document_ids' => ['doc-v1'], 'consent' => true];
    }

    private function start(BacklogProject $project, AiConnection $connection, ?string $parent = null): AiAnalysis
    {
        $id = $this->postJson(route('ai.analyze', $project), [...$this->payload($project, $connection), 'parent_node_id' => $parent])->assertAccepted()->json('id');

        return AiAnalysis::findOrFail($id);
    }

    private function response(): array
    {
        return ['summary' => 'A source-backed goal for human review.', 'nodes' => [[
            'key' => 'welcome', 'type' => 'goal', 'title' => 'Help new starters feel prepared', 'description' => 'Provide a welcome pack before day one.',
            'supports_parent' => 'Help new starters prepare before their first day.', 'rationale' => 'The selected brief requires a welcome pack.', 'criteria' => [], 'assumptions' => ['HR will coordinate the pack.'], 'dependencies' => [],
            'evidence' => [['document_id' => 'doc-v1', 'line' => 2, 'end_line' => 2, 'quote' => 'Every new starter must receive a welcome pack before day one.']],
        ]], 'questions' => []];
    }
}
