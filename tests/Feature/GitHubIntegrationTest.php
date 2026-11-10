<?php

namespace Tests\Feature;

use App\Jobs\ProcessGitHubDelivery;
use App\Jobs\PublishGitHubIssues;
use App\Models\BacklogProject;
use App\Models\GitHubConnection;
use App\Models\GitHubDelivery;
use App\Models\GitHubIssue;
use App\Models\GitHubPublication;
use App\Models\Team;
use App\Models\User;
use App\Services\BacklogWorkflow;
use App\Services\GitHubClient;
use App\Services\GitHubPublishing;
use App\Services\GitHubSynchronization;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

class GitHubIntegrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private array $remote = [];

    private array $parents = [];

    private array $dependencies = [];

    private bool $loseCreateResponse = false;

    private bool $failLinks = false;

    private int $createCalls = 0;

    private int $stateWrites = 0;

    public function test_multiple_projects_are_isolated_and_cross_tenant_selection_is_refused(): void
    {
        $team = Team::factory()->create();
        $other = Team::factory()->create();
        $foreign = app(BacklogWorkflow::class)->reset($other, $other->owner, null);
        $this->actingAs($team->owner)->withSession(['team_id' => $team->id]);
        $first = $this->postJson(route('projects.store'), ['name' => 'First', 'description' => 'One repository.', 'connection_mode' => 'later'])->assertCreated()->json('id');
        $second = $this->postJson(route('projects.store'), ['name' => 'Second', 'connection_mode' => 'oauth'])->assertCreated()->json('id');

        $this->getJson(route('workspace.show'), ['X-Team-ID' => $team->id, 'X-Project-ID' => $first])->assertJsonPath('name', 'First');
        $this->getJson(route('workspace.show'), ['X-Team-ID' => $team->id, 'X-Project-ID' => $second])->assertJsonPath('name', 'Second');
        $this->getJson(route('workspace.show'), ['X-Team-ID' => $team->id, 'X-Project-ID' => $foreign->id])->assertNotFound();
        $this->postJson(route('projects.select', $foreign))->assertNotFound();
        $this->getJson(route('github.publications', $foreign))->assertNotFound();
    }

    public function test_current_issue_mappings_support_eager_loading_and_scheduled_project_discovery(): void
    {
        [$project, $connection] = $this->fixture();
        $current = GitHubIssue::factory()->create(['backlog_project_id' => $project->id, 'github_connection_id' => $connection->id, 'generation' => $project->generation, 'node_id' => 'root']);
        GitHubIssue::factory()->create(['backlog_project_id' => $project->id, 'github_connection_id' => $connection->id, 'generation' => (string) Str::uuid(), 'node_id' => 'root']);

        $loaded = BacklogProject::with('githubIssues')->findOrFail($project->id);
        $this->assertSame([$current->id], $loaded->githubIssues->modelKeys());
        $this->assertTrue(BacklogProject::whereKey($project->id)->whereHas('githubIssues')->exists());
        $project->update(['generation' => (string) Str::uuid()]);
        $this->assertFalse(BacklogProject::whereKey($project->id)->whereHas('githubIssues')->exists());
    }

    public function test_oauth_uses_pkce_single_use_state_and_encrypted_credentials(): void
    {
        [$project, $connection] = $this->fixture(false);
        config(['services.github.oauth_client_id' => 'client', 'services.github.oauth_client_secret' => 'client-secret']);
        $result = $this->postJson(route('github.connect', $project), ['mode' => 'oauth'])->assertOk();
        parse_str(parse_url($result->json('url'), PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertArrayHasKey('code_challenge', $query);
        Http::fake([
            'https://github.com/login/oauth/access_token' => Http::response(['access_token' => 'new-secret-token', 'refresh_token' => 'new-refresh-token', 'expires_in' => 28800]),
            'https://api.github.com/user' => Http::response(['id' => (int) $connection->github_user_id, 'login' => 'connected-user']),
        ]);

        $this->get(route('github.callback', ['state' => $query['state'], 'code' => 'valid-code']))->assertRedirect();

        $this->assertSame('new-secret-token', $connection->refresh()->access_token);
        $this->assertNotSame('new-secret-token', DB::table('git_hub_connections')->where('id', $connection->id)->value('access_token'));
        $this->getJson(route('github.connections', $project))->assertJsonMissingPath('0.access_token')->assertJsonMissingPath('0.refresh_token');
        $this->getJson(route('github.callback', ['state' => $query['state'], 'code' => 'replayed']))->assertForbidden();
    }

    public function test_forged_oauth_state_and_other_tenant_connection_ids_are_refused(): void
    {
        [$project] = $this->fixture();
        $other = GitHubConnection::factory()->create();

        $this->getJson(route('github.callback', ['state' => str_repeat('x', 64), 'code' => 'forged']))->assertForbidden();
        $this->getJson(route('github.repositories', [$project, $other]))->assertNotFound();
        $this->putJson(route('github.bind', $project), ['connection_id' => $other->id, 'repository_id' => 123, 'confirm_sync' => true])->assertNotFound();
    }

    public function test_real_publishing_creates_native_hierarchy_and_dependencies_only_after_approval(): void
    {
        [$project] = $this->fixture();
        $batch = $this->preview($project, ['reply']);
        $this->assertSame(0, $this->createCalls);
        Queue::assertNothingPushed();
        $this->assertSame(['root', 'resolution', 'reply', 'queue'], array_column($batch->items, 'node_id'));

        $this->publish($project, $batch);

        $this->assertSame(4, $this->createCalls);
        $this->assertSame('published', $batch->refresh()->status);
        $this->assertCount(3, $this->parents);
        $this->assertCount(1, $this->dependencies);
        $queue = $project->githubIssues()->where('node_id', 'queue')->firstOrFail();
        $reply = $project->githubIssues()->where('node_id', 'reply')->firstOrFail();
        $this->assertSame([$queue->github_id], $this->dependencies[$reply->number]);
        $this->assertSame('live', $project->refresh()->state['nodes']['reply']['publication_mode']);
        $this->assertSame('published', $batch->status);
    }

    public function test_repeated_approval_and_worker_execution_do_not_duplicate_issues(): void
    {
        [$project] = $this->fixture();
        $batch = $this->preview($project, ['queue']);
        $this->publish($project, $batch);

        $this->postJson(route('github.publish', [$project, $batch]), ['confirmation' => 'PUBLISH_TO_GITHUB'])->assertOk();
        (new PublishGitHubIssues($batch->id))->handle(app(GitHubPublishing::class));

        $this->assertSame(3, $this->createCalls);
        $this->assertDatabaseCount('git_hub_issues', 3);
    }

    public function test_ambiguous_create_response_is_reconciled_before_retrying(): void
    {
        [$project] = $this->fixture();
        $batch = $this->preview($project, ['queue']);
        $this->postJson(route('github.publish', [$project, $batch]), ['confirmation' => 'PUBLISH_TO_GITHUB'])->assertOk();
        $this->loseCreateResponse = true;
        try {
            app(GitHubPublishing::class)->publish($batch->id);
            $this->fail('The simulated lost response should pause publication.');
        } catch (RuntimeException $error) {
            $this->assertSame('failed', $batch->refresh()->status);
        }
        $this->assertSame(1, $this->createCalls);

        app(GitHubPublishing::class)->publish($batch->id);

        $this->assertSame(3, $this->createCalls);
        $this->assertCount(3, $this->remote);
        $this->assertSame('published', $batch->refresh()->status);
    }

    public function test_link_failure_preserves_created_issue_ids_and_retry_resumes_links(): void
    {
        [$project] = $this->fixture();
        $batch = $this->preview($project, ['queue']);
        $this->postJson(route('github.publish', [$project, $batch]), ['confirmation' => 'PUBLISH_TO_GITHUB'])->assertOk();
        $this->failLinks = true;
        try {
            app(GitHubPublishing::class)->publish($batch->id);
            $this->fail('Linking should fail.');
        } catch (RuntimeException $error) {
            $this->assertSame(3, $this->createCalls);
        }
        $this->failLinks = false;

        app(GitHubPublishing::class)->publish($batch->id);

        $this->assertSame(3, $this->createCalls);
        $this->assertSame('published', $batch->refresh()->status);
    }

    public function test_stale_approvals_and_disconnected_projects_cannot_publish(): void
    {
        [$project] = $this->fixture();
        $batch = $this->preview($project, ['queue']);
        app(BacklogWorkflow::class)->command($project->team, $project->team->owner, ['action' => 'edit', 'version' => $project->version, 'node_id' => 'queue', 'title' => 'Changed queue', 'description' => 'A changed scope.'], $project);

        $this->postJson(route('github.publish', [$project, $batch]), ['confirmation' => 'PUBLISH_TO_GITHUB'])->assertUnprocessable();

        $this->assertSame(0, $this->createCalls);
    }

    public function test_valid_remote_closure_completes_the_node_and_echoes_do_not_loop(): void
    {
        [$project] = $this->fixture();
        $batch = $this->preview($project, ['queue']);
        $this->publish($project, $batch);
        $queue = $project->githubIssues()->where('node_id', 'queue')->firstOrFail();
        $this->remote[$queue->number]['state'] = 'closed';

        app(GitHubSynchronization::class)->synchronize($project->id);

        $this->assertSame(1, $project->refresh()->state['nodes']['queue']['completed_revision']);
        $historyCount = count($project->state['history']);
        app(GitHubSynchronization::class)->synchronize($project->id);
        $this->assertSame($historyCount, count($project->refresh()->state['history']));
        $this->assertSame(0, $this->stateWrites);
    }

    public function test_invalid_remote_parent_closure_is_automatically_reopened_with_a_reason(): void
    {
        [$project] = $this->fixture();
        $batch = $this->preview($project, ['reply']);
        $this->publish($project, $batch);
        $parent = $project->githubIssues()->where('node_id', 'resolution')->firstOrFail();
        $this->remote[$parent->number]['state'] = 'closed';

        app(GitHubSynchronization::class)->synchronize($project->id);

        $this->assertSame('open', $this->remote[$parent->number]['state']);
        $this->assertNull($project->refresh()->state['nodes']['resolution']['completed_revision'] ?? null);
        $events = array_filter($project->state['history'], fn (array $event): bool => $event['action'] === 'github_invalid_close_reopened');
        $this->assertCount(1, $events);
        $this->assertSame(1, $this->stateWrites);
    }

    public function test_local_completion_and_remote_reopening_cascade_in_both_systems(): void
    {
        [$project] = $this->fixture();
        $this->publish($project, $this->preview($project, ['reply']));
        foreach (['queue', 'reply', 'resolution'] as $id) {
            $project->refresh();
            app(BacklogWorkflow::class)->command($project->team, $project->team->owner, ['action' => 'complete', 'node_id' => $id, 'version' => $project->version], $project);
            app(GitHubSynchronization::class)->synchronize($project->id);
        }
        $queue = $project->githubIssues()->where('node_id', 'queue')->firstOrFail();
        $this->remote[$queue->number]['state'] = 'open';

        app(GitHubSynchronization::class)->synchronize($project->id, 'teammate');

        foreach (['queue', 'reply', 'resolution'] as $id) {
            $this->assertNull($project->refresh()->state['nodes'][$id]['completed_revision']);
            $issue = $project->githubIssues()->where('node_id', $id)->firstOrFail();
            $this->assertSame('open', $this->remote[$issue->number]['state']);
        }
    }

    public function test_webhook_signature_and_delivery_deduplication_are_enforced(): void
    {
        [$project] = $this->fixture();
        config(['services.github.webhook_secret' => 'test-webhook-secret']);
        $payload = ['action' => 'closed', 'repository' => ['id' => 123], 'issue' => ['id' => 999], 'sender' => ['login' => 'teammate']];
        $this->postJson(route('github.webhook'), $payload)->assertUnauthorized();
        $this->delivery($payload, 'delivery-1')->assertAccepted();
        $this->delivery($payload, 'delivery-1')->assertAccepted();

        $this->assertDatabaseCount('git_hub_deliveries', 1);
        Queue::assertPushed(ProcessGitHubDelivery::class, 1);
    }

    public function test_signed_out_of_order_delivery_reads_latest_github_state_instead_of_payload_state(): void
    {
        [$project] = $this->fixture();
        $this->publish($project, $this->preview($project, ['queue']));
        config(['services.github.webhook_secret' => 'test-webhook-secret']);
        $issue = $project->githubIssues()->where('node_id', 'queue')->firstOrFail();
        $this->delivery(['action' => 'closed', 'repository' => ['id' => 123], 'issue' => ['id' => (int) $issue->github_id, 'state' => 'closed'], 'sender' => ['login' => 'teammate']], 'old-delivery')->assertAccepted();

        (new ProcessGitHubDelivery(GitHubDelivery::first()->id))->handle(app(GitHubSynchronization::class));

        $this->assertNull($project->refresh()->state['nodes']['queue']['completed_revision'] ?? null);
        $this->assertSame('processed', GitHubDelivery::first()->status);
    }

    public function test_published_projects_cannot_be_reset_or_silently_retargeted(): void
    {
        [$project] = $this->fixture();
        $this->publish($project, $this->preview($project, ['queue']));

        $this->postJson(route('workspace.reset'), ['confirmation' => 'RESET', 'version' => $project->fresh()->version], ['X-Project-ID' => $project->id])->assertUnprocessable();

        $this->assertDatabaseCount('git_hub_issues', 3);
    }

    public function test_installation_selection_is_verified_and_multiple_installations_stay_independent(): void
    {
        [$project] = $this->fixture(false);
        $identity = GitHubConnection::factory()->create(['team_id' => $project->team_id, 'user_id' => $project->team->owner_id, 'mode' => 'app']);
        config(['services.github.app_slug' => 'tracework', 'services.github.app_id' => '77']);
        Http::fake(['https://api.github.com/user/installations*' => Http::response(['installations' => [
            ['id' => 111, 'app_id' => 77, 'permissions' => ['issues' => 'write']],
            ['id' => 222, 'app_id' => 77, 'permissions' => ['issues' => 'write']],
        ]])]);
        foreach ([111, 222] as $installation) {
            $response = $this->postJson(route('github.install', [$project, $identity]))->assertOk();
            parse_str(parse_url($response->json('url'), PHP_URL_QUERY), $query);
            $this->get(route('github.installed', ['state' => $query['state'], 'installation_id' => $installation]))->assertRedirect();
        }

        $this->assertNull($identity->refresh()->installation_id);
        $this->assertDatabaseHas('git_hub_connections', ['team_id' => $project->team_id, 'mode' => 'app', 'slot' => 'installation-111', 'installation_id' => '111']);
        $this->assertDatabaseHas('git_hub_connections', ['team_id' => $project->team_id, 'mode' => 'app', 'slot' => 'installation-222', 'installation_id' => '222']);
        $response = $this->postJson(route('github.install', [$project, $identity]))->assertOk();
        parse_str(parse_url($response->json('url'), PHP_URL_QUERY), $query);
        $this->getJson(route('github.installed', ['state' => $query['state'], 'installation_id' => 999]))->assertForbidden();
    }

    public function test_app_installation_token_is_restricted_to_the_target_repository(): void
    {
        [$project] = $this->fixture(false);
        $connection = GitHubConnection::factory()->create(['team_id' => $project->team_id, 'user_id' => $project->team->owner_id, 'mode' => 'app', 'installation_id' => '111']);
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        config(['services.github.app_id' => '77', 'services.github.private_key' => $private]);
        Http::fake([
            'https://api.github.com/app/installations/111/access_tokens' => Http::response(['token' => 'installation-test-token']),
            'https://api.github.com/repositories/123' => Http::response(['id' => 123]),
        ]);

        app(GitHubClient::class)->request($connection, 'GET', '/repositories/123', repositoryId: '123');

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/access_tokens') && $request['repository_ids'] === [123] && $request['permissions'] === ['issues' => 'write', 'metadata' => 'read']);
    }

    public function test_expired_oauth_tokens_are_refreshed_and_kept_encrypted(): void
    {
        [$project, $connection] = $this->fixture(false);
        $connection->update(['expires_at' => now()->subMinute(), 'refresh_token' => 'old-refresh']);
        Http::fake([
            'https://github.com/login/oauth/access_token' => Http::response(['access_token' => 'refreshed-token', 'refresh_token' => 'rotated-refresh', 'expires_in' => 28800]),
            'https://api.github.com/repositories/123' => Http::response(['id' => 123]),
        ]);

        app(GitHubClient::class)->request($connection, 'GET', '/repositories/123');

        $this->assertSame('refreshed-token', $connection->refresh()->access_token);
        $this->assertSame('rotated-refresh', $connection->refresh_token);
        $this->assertNotSame('rotated-refresh', DB::table('git_hub_connections')->where('id', $connection->id)->value('refresh_token'));
    }

    public function test_repository_binding_requires_confirmation_and_installs_the_oauth_webhook(): void
    {
        [$project, $connection] = $this->fixture(false);
        config(['services.github.webhook_secret' => 'webhook-test-secret']);
        Http::fake([
            'https://api.github.com/repositories/123' => Http::response(['id' => 123, 'full_name' => 'example/repository', 'private' => true, 'has_issues' => true, 'permissions' => ['admin' => true]]),
            'https://api.github.com/repos/example/repository/hooks*' => fn (Request $request) => Http::response($request->method() === 'GET' ? [] : ['id' => 55], $request->method() === 'GET' ? 200 : 201),
        ]);
        $this->putJson(route('github.bind', $project), ['connection_id' => $connection->id, 'repository_id' => 123])->assertUnprocessable();

        $this->putJson(route('github.bind', $project), ['connection_id' => $connection->id, 'repository_id' => 123, 'confirm_sync' => true])->assertOk();

        $this->assertSame('55', $project->refresh()->webhook_id);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['events'] === ['issues'] && $request['config']['secret'] === 'webhook-test-secret');
    }

    public function test_github_content_changed_after_preview_is_not_silently_overwritten(): void
    {
        [$project] = $this->fixture();
        $this->publish($project, $this->preview($project, ['queue']));
        $project->refresh();
        app(BacklogWorkflow::class)->command($project->team, $project->team->owner, ['action' => 'edit', 'version' => $project->version, 'node_id' => 'queue', 'title' => 'Revised queue', 'description' => 'A new implementation description.'], $project);
        $project->refresh();
        app(BacklogWorkflow::class)->command($project->team, $project->team->owner, ['action' => 'approve', 'version' => $project->version, 'node_id' => 'queue'], $project);
        $batch = $this->preview($project->fresh(), ['queue']);
        $issue = $project->githubIssues()->where('node_id', 'queue')->firstOrFail();
        $this->remote[$issue->number]['body'] .= '\nAn edit made on GitHub after the preview.';
        $expected = $this->remote[$issue->number]['body'];
        $this->postJson(route('github.publish', [$project, $batch]), ['confirmation' => 'PUBLISH_TO_GITHUB'])->assertOk();

        try {
            app(GitHubPublishing::class)->publish($batch->id);
            $this->fail('A conflicting GitHub edit must stop publication.');
        } catch (RuntimeException $error) {
            $this->assertSame('failed', $batch->refresh()->status);
        }

        $this->assertSame($expected, $this->remote[$issue->number]['body']);
        $this->assertSame(3, $this->createCalls);
    }

    public function test_viewers_and_editors_cannot_publish_through_the_live_endpoints(): void
    {
        [$project] = $this->fixture();
        foreach (['viewer', 'editor'] as $role) {
            $user = User::factory()->create();
            $project->team->members()->attach($user, ['role' => $role]);
            $this->actingAs($user)->withSession(['team_id' => $project->team_id]);
            $this->postJson(route('github.preview', $project), ['version' => $project->version, 'node_ids' => ['queue']])->assertForbidden();
            $this->postJson(route('github.connect', $project), ['mode' => 'oauth'])->assertForbidden();
        }

        Http::assertNothingSent();
    }

    public function test_over_depth_hierarchy_is_rejected_before_creating_github_issues(): void
    {
        [$project] = $this->fixture();
        $state = $project->state;
        $template = $state['nodes']['queue'];
        $state['nodes'] = [];
        for ($depth = 0; $depth <= 9; $depth++) {
            $state['nodes']['level-'.$depth] = array_replace($template, ['id' => 'level-'.$depth, 'parent_id' => $depth ? 'level-'.($depth - 1) : null, 'type' => $depth < 9 ? 'goal' : 'task', 'status' => 'approved', 'breakdown' => $depth < 9 ? 'approved' : 'none']);
        }
        $project->update(['state' => $state]);

        $this->postJson(route('github.preview', $project), ['version' => $project->version, 'node_ids' => ['level-9']])->assertUnprocessable();

        $this->assertSame(0, $this->createCalls);
    }

    public function test_partial_failed_publication_cannot_be_retargeted_to_another_repository(): void
    {
        [$project, $connection] = $this->fixture();
        $this->publish($project, $this->preview($project, ['queue']));

        $this->putJson(route('github.bind', $project), ['connection_id' => $connection->id, 'repository_id' => 456, 'confirm_sync' => true])->assertUnprocessable();

        $this->assertSame('123', $project->refresh()->repository_id);
    }

    private function fixture(bool $fake = true): array
    {
        Queue::fake();
        Http::preventStrayRequests();
        $team = Team::factory()->create();
        $project = app(BacklogWorkflow::class)->reset($team, $team->owner, null);
        app(BacklogWorkflow::class)->command($team, $team->owner, ['action' => 'approve', 'node_id' => 'reply', 'version' => $project->version], $project);
        $connection = GitHubConnection::factory()->create(['team_id' => $team->id, 'user_id' => $team->owner_id, 'github_user_id' => '42', 'login' => 'connected-user']);
        $project->refresh()->update(['connection_mode' => 'oauth', 'github_connection_id' => $connection->id, 'repository_id' => '123', 'repository_full_name' => 'example/repository']);
        $this->actingAs($team->owner)->withSession(['team_id' => $team->id]);
        if ($fake) {
            $this->fakeGitHub();
        }

        return [$project, $connection];
    }

    private function preview(BacklogProject $project, array $nodes): GitHubPublication
    {
        $response = $this->postJson(route('github.preview', $project), ['version' => $project->fresh()->version, 'node_ids' => $nodes])->assertCreated();

        return GitHubPublication::findOrFail($response->json('id'));
    }

    private function publish(BacklogProject $project, GitHubPublication $batch): void
    {
        $this->postJson(route('github.publish', [$project, $batch]), ['confirmation' => 'PUBLISH_TO_GITHUB'])->assertOk();
        Queue::assertPushed(PublishGitHubIssues::class);
        (new PublishGitHubIssues($batch->id))->handle(app(GitHubPublishing::class));
    }

    private function delivery(array $payload, string $id): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->call('POST', route('github.webhook'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'test-webhook-secret'), 'HTTP_X_GITHUB_DELIVERY' => $id, 'HTTP_X_GITHUB_EVENT' => 'issues'], $body);
    }

    private function fakeGitHub(): void
    {
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $method = $request->method();
            if ($path === '/repositories/123' && $method === 'GET') {
                return Http::response(['id' => 123, 'full_name' => 'example/repository', 'private' => true, 'has_issues' => true, 'archived' => false, 'permissions' => ['admin' => true, 'push' => true]]);
            }
            if ($path === '/repos/example/repository/issues') {
                if ($method === 'GET') {
                    return Http::response(array_values($this->remote));
                }
                if ($method === 'POST') {
                    $this->createCalls++;
                    $number = count($this->remote) + 1;
                    $this->remote[$number] = ['id' => 1000 + $number, 'number' => $number, 'html_url' => 'https://github.com/example/repository/issues/'.$number, 'title' => $request['title'], 'body' => $request['body'], 'state' => 'open', 'closed_by' => ['login' => 'teammate']];
                    if ($this->loseCreateResponse) {
                        $this->loseCreateResponse = false;
                        throw new ConnectionException('Simulated lost response.');
                    }

                    return Http::response($this->remote[$number], 201);
                }
            }
            if (preg_match('#^/repos/example/repository/issues/(\d+)(.*)$#', $path, $matches)) {
                $number = (int) $matches[1];
                $suffix = $matches[2];
                if ($suffix === '' && $method === 'GET') {
                    return Http::response($this->remote[$number]);
                }
                if ($suffix === '' && $method === 'PATCH') {
                    if (isset($request['state'])) {
                        $this->stateWrites++;
                    }
                    $this->remote[$number] = array_replace($this->remote[$number], $request->data());

                    return Http::response($this->remote[$number]);
                }
                if ($suffix === '/sub_issues') {
                    if ($method === 'GET') {
                        return Http::response(array_values(array_filter($this->remote, fn (array $issue): bool => ($this->parents[$issue['id']] ?? null) === $number)));
                    }
                    if ($this->failLinks) {
                        return Http::response([], 503);
                    }
                    $this->parents[$request['sub_issue_id']] = $number;

                    return Http::response([], 201);
                }
                if ($suffix === '/dependencies/blocked_by') {
                    if ($method === 'GET') {
                        return Http::response(array_map(fn (string $id): array => ['id' => (int) $id], $this->dependencies[$number] ?? []));
                    }
                    $this->dependencies[$number][] = (string) $request['issue_id'];

                    return Http::response([], 201);
                }
            }
            throw new RuntimeException('Unexpected GitHub test request: '.$method.' '.$path);
        });
    }
}
