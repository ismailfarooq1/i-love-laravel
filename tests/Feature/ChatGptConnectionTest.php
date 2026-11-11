<?php

namespace Tests\Feature;

use App\Models\AiConnection;
use App\Models\BacklogProject;
use App\Models\Team;
use App\Models\User;
use App\Services\ChatGptAccess;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChatGptConnectionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_local_callback_finishes_in_original_session_and_saves_private_encrypted_credentials(): void
    {
        [$project, $parameters, $pending] = $this->attempt();
        $this->fakeAuthorization($parameters, $pending);
        $callback = $this->callbackUrl($parameters);
        $this->get($callback)->assertRedirect('http://localhost/chatgpt/finish')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertDatabaseCount('ai_connections', 0);
        $this->get('http://localhost/chatgpt/finish')->assertRedirectContains('ai_connected=chatgpt');
        $connection = AiConnection::firstOrFail();
        $this->assertSame($project->team->owner_id, $connection->user_id);
        $this->assertFalse($connection->shared_with_team);
        $this->assertSame('oaiapp_new', $connection->credentials['client_id']);
        $this->assertSame('new-access-token', $connection->credentials['access_token']);
        $this->assertStringNotContainsString('new-access-token', $connection->getRawOriginal('credentials'));
        $this->getJson(route('ai.index', $project))->assertDontSee('new-access-token')->assertJsonPath('connections.0.email', 'person@example.com');
        $this->get($callback)->assertForbidden();
        $this->get('http://localhost/chatgpt/finish')->assertForbidden();
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/oauth/token') && $request['code_verifier'] === $pending['verifier'] && $request['redirect_uri'] === $parameters['redirect_uri'] && ! isset($request['client_secret']));
    }

    public function test_callback_rejects_public_host_wrong_state_and_duplicate_parameters_without_exchanging_code(): void
    {
        [, $parameters] = $this->attempt();
        $callback = $this->callbackUrl($parameters);
        $this->get(str_replace('http://127.0.0.1:8000', 'https://example.ngrok-free.dev', $callback))->assertForbidden();
        $this->get($callback.'&state=another')->assertForbidden();
        $this->get(str_replace($parameters['state'], str_repeat('x', 64), $callback))->assertForbidden();
        Http::assertNothingSent();
    }

    #[DataProvider('invalidClaims')]
    public function test_invalid_identity_never_installs_connection(string $claim, mixed $value): void
    {
        [, $parameters, $pending] = $this->attempt();
        $this->fakeAuthorization($parameters, $pending, [$claim => $value]);
        $this->get($this->callbackUrl($parameters))->assertRedirect();
        $this->get('http://localhost/chatgpt/finish')->assertRedirectContains('ai_error=authorization_failed');
        $this->assertDatabaseCount('ai_connections', 0);
    }

    public static function invalidClaims(): array
    {
        return [['nonce', 'wrong'], ['aud', 'other-client'], ['iss', 'https://attacker.example'], ['exp', 1], ['sub', ''], ['azp', 'another-client']];
    }

    public function test_declined_consent_and_expired_attempt_never_exchange_tokens(): void
    {
        [$project, $parameters] = $this->attempt();
        $this->get($parameters['redirect_uri'].'?'.http_build_query(['state' => $parameters['state'], 'error' => 'access_denied']))->assertRedirect();
        $this->get('http://localhost/chatgpt/finish')->assertRedirectContains('ai_error');
        [, $second] = $this->attempt($project);
        $this->travel(11)->minutes();
        $this->get($this->callbackUrl($second))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_callback_cannot_install_into_another_browser_session_or_user(): void
    {
        [, $parameters, $pending] = $this->attempt();
        $this->fakeAuthorization($parameters, $pending);
        $this->get($this->callbackUrl($parameters))->assertRedirect();
        $this->actingAs(User::factory()->create());
        $this->get('http://localhost/chatgpt/finish')->assertForbidden();
        $this->assertDatabaseCount('ai_connections', 0);
    }

    public function test_reconnect_uses_saved_client_and_refuses_changed_identity(): void
    {
        $project = BacklogProject::factory()->create(['team_id' => Team::factory()]);
        $connection = AiConnection::factory()->create(['team_id' => $project->team_id]);
        [, $parameters, $pending] = $this->attempt($project, $connection);
        $this->assertSame('oaiapp_test', $parameters['client_id']);
        $this->assertArrayNotHasKey('agent_name_hint', $parameters);
        $this->fakeAuthorization($parameters, $pending, ['sub' => 'different-person'], 'oaiapp_test');
        $this->get($this->callbackUrl($parameters, 'oaiapp_test'))->assertRedirect();
        $this->get('http://localhost/chatgpt/finish')->assertRedirectContains('ai_error');
        $this->assertSame('test-access-token', $connection->fresh()->credentials['access_token']);
    }

    public function test_models_and_disconnect_are_private_even_to_other_team_admins(): void
    {
        $project = BacklogProject::factory()->create(['team_id' => Team::factory()]);
        $connection = AiConnection::factory()->create(['team_id' => $project->team_id]);
        $member = User::factory()->create();
        $project->team->members()->attach($member, ['role' => 'admin']);
        $this->actingAs($member);
        Http::preventStrayRequests();
        $this->getJson(route('ai.index', $project))->assertOk()->assertJsonCount(0, 'connections');
        $this->getJson(route('chatgpt.models', [$project, $connection]))->assertNotFound();
        $this->patchJson(route('chatgpt.update', [$project, $connection]), ['revision' => 1, 'model' => 'test-model'])->assertNotFound();
        $this->deleteJson(route('ai.disconnect', [$project, $connection]))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_refresh_rotates_tokens_and_preserves_revision_then_disconnect_revokes(): void
    {
        $connection = AiConnection::factory()->create();
        $connection->update(['credentials' => [...$connection->credentials, 'expires_at' => 1]]);
        Http::preventStrayRequests();
        Http::fake([
            'https://auth.openai.com/api/accounts/oauth/token' => Http::response(['access_token' => 'rotated-access', 'refresh_token' => 'rotated-refresh', 'expires_in' => 3600, 'token_type' => 'Bearer']),
            'https://auth.openai.com/.well-known/openid-configuration' => Http::response(['revocation_endpoint' => 'https://auth.openai.com/revoke']),
            'https://auth.openai.com/revoke' => Http::response('', 200),
        ]);
        $access = app(ChatGptAccess::class);
        $this->assertSame('rotated-access', $access->token($connection));
        $this->assertSame('rotated-access', $access->token($connection));
        Http::assertSentCount(1);
        $this->assertSame(1, $connection->fresh()->revision);
        $this->assertTrue($access->disconnect($connection));
        $this->assertFalse($connection->fresh()->connected());
        $this->assertSame('oaiapp_test', $connection->fresh()->credentials['client_id']);
        Http::assertSent(fn ($request) => $request->url() === 'https://auth.openai.com/revoke' && $request['token'] === 'rotated-refresh');
    }

    public function test_failed_revocation_clears_local_tokens_and_warns_user(): void
    {
        $project = BacklogProject::factory()->create(['team_id' => Team::factory()]);
        $connection = AiConnection::factory()->create(['team_id' => $project->team_id]);
        $this->actingAs($project->team->owner);
        Http::fake(['*' => Http::response([], 503)]);
        $this->deleteJson(route('ai.disconnect', [$project, $connection]))->assertOk()->assertJsonPath('message', 'Disconnected locally. Remote revocation could not be confirmed; disconnect Tracework in ChatGPT settings too.');
        $this->assertFalse($connection->fresh()->connected());
    }

    public function test_missing_plan_scope_and_invalid_signature_are_rejected(): void
    {
        [$project, $parameters, $pending] = $this->attempt();
        $this->fakeAuthorization($parameters, $pending, scope: 'openid profile email');
        $this->get($this->callbackUrl($parameters))->assertRedirect();
        $this->get('http://localhost/chatgpt/finish')->assertRedirectContains('ai_error');
        [, $parameters, $pending] = $this->attempt($project);
        $this->fakeAuthorization($parameters, $pending, badSignature: true);
        $this->get($this->callbackUrl($parameters))->assertRedirect();
        $this->get('http://localhost/chatgpt/finish')->assertRedirectContains('ai_error');
        $this->assertDatabaseCount('ai_connections', 0);
    }

    public function test_ngrok_origin_returns_to_ngrok_and_requires_original_browser_secret(): void
    {
        config(['app.url' => 'https://tracework.ngrok-free.dev']);
        $project = BacklogProject::factory()->create(['team_id' => Team::factory()]);
        $this->actingAs($project->team->owner)->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
        Http::preventStrayRequests();
        $this->partialMock(ChatGptAccess::class)->shouldReceive('hostId')->andReturn('urn:uuid:12345678-1234-4234-8234-123456789abc');
        $response = $this->postJson('https://tracework.ngrok-free.dev/projects/'.$project->id.'/chatgpt/connect', ['local_confirmation' => true])->assertOk();
        parse_str(parse_url($response->json('url'), PHP_URL_QUERY), $parameters);
        $pending = json_decode(Crypt::decryptString(Cache::get('chatgpt-pending-'.$parameters['state'])), true);
        $this->fakeAuthorization($parameters, $pending);
        $this->get($this->callbackUrl($parameters))->assertRedirect('https://tracework.ngrok-free.dev/chatgpt/finish');
        $this->withSession(['chatgpt.browser_secret' => 'wrong-browser-secret']);
        $this->get('https://tracework.ngrok-free.dev/chatgpt/finish')->assertForbidden();
        $this->assertDatabaseCount('ai_connections', 0);
    }

    public function test_expired_refresh_session_is_disabled_but_transient_failure_preserves_credentials(): void
    {
        $connection = AiConnection::factory()->create();
        $connection->update(['credentials' => [...$connection->credentials, 'expires_at' => 1]]);
        Http::fake(['https://auth.openai.com/api/accounts/oauth/token' => Http::sequence()->push(['error' => 'unavailable'], 503)->push(['error' => 'invalid_grant'], 400)]);
        foreach ([true, false] as $stillConnected) {
            try {
                app(ChatGptAccess::class)->token($connection);
                $this->fail('Token refresh should fail.');
            } catch (\RuntimeException) {
                $this->assertSame($stillConnected, $connection->fresh()->connected());
            }
        }
        Http::assertSentCount(2);
    }

    public function test_model_selection_checks_catalog_and_revision_and_persists_on_reload(): void
    {
        $project = BacklogProject::factory()->create(['team_id' => Team::factory()]);
        $connection = AiConnection::factory()->create(['team_id' => $project->team_id]);
        $this->actingAs($project->team->owner);
        $this->fakeChatGpt([]);
        $this->patchJson(route('chatgpt.update', [$project, $connection]), ['model' => 'made-up-model', 'revision' => 1])->assertUnprocessable();
        $this->patchJson(route('chatgpt.update', [$project, $connection]), ['model' => 'test-model', 'revision' => 50])->assertConflict();
        $this->patchJson(route('chatgpt.update', [$project, $connection]), ['model' => 'test-model', 'revision' => 1])->assertOk();
        $this->getJson(route('ai.index', $project))->assertJsonPath('connections.0.model', 'test-model')->assertJsonPath('connections.0.revision', 2);
        Http::assertSentCount(3);
    }

    private function attempt(?BacklogProject $project = null, ?AiConnection $connection = null): array
    {
        config(['app.url' => 'http://localhost', 'services.chatgpt.callback_port' => 8000]);
        Http::preventStrayRequests();
        $project ??= BacklogProject::factory()->create(['team_id' => Team::factory()]);
        $this->actingAs($project->team->owner)->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
        $this->partialMock(ChatGptAccess::class)->shouldReceive('hostId')->andReturn('urn:uuid:12345678-1234-4234-8234-123456789abc');
        $response = $this->postJson('http://localhost/projects/'.$project->id.'/chatgpt/connect', ['local_confirmation' => true, 'connection_id' => $connection?->id])->assertOk();
        parse_str(parse_url($response->json('url'), PHP_URL_QUERY), $parameters);
        $pending = json_decode(Crypt::decryptString(Cache::get('chatgpt-pending-'.$parameters['state'])), true);
        $this->assertSame('http://127.0.0.1:8000/auth/chatgpt/callback', $parameters['redirect_uri']);
        $this->assertSame('S256', $parameters['code_challenge_method']);

        return [$project, $parameters, $pending];
    }

    private function callbackUrl(array $parameters, string $client = 'oaiapp_new'): string
    {
        return $parameters['redirect_uri'].'?'.http_build_query(['state' => $parameters['state'], 'code' => 'test-code', 'client_id' => $client]);
    }

    private function fakeAuthorization(array $parameters, array $pending, array $overrides = [], string $client = 'oaiapp_new', string $scope = ChatGptAccess::SCOPES, bool $badSignature = false): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $details = openssl_pkey_get_details($key)['rsa'];
        $encode = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $token = JWT::encode([...['iss' => ChatGptAccess::ISSUER, 'sub' => 'person-subject', 'aud' => $client, 'iat' => time(), 'exp' => time() + 3600, 'nonce' => $pending['nonce'], 'email' => 'person@example.com'], ...$overrides], $private, 'RS256', 'test-key');
        if ($badSignature) {
            $parts = explode('.', $token);
            $parts[2] = str_repeat('a', strlen($parts[2]));
            $token = implode('.', $parts);
        }
        Http::fake([
            'https://auth.openai.com/api/accounts/oauth/token' => Http::response(['id_token' => $token, 'access_token' => 'new-access-token', 'refresh_token' => 'new-refresh-token', 'scope' => $scope, 'token_type' => 'Bearer', 'expires_in' => 3600]),
            'https://auth.openai.com/.well-known/jwks.json' => Http::response(['keys' => [['kid' => 'test-key', 'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'n' => $encode($details['n']), 'e' => $encode($details['e'])]]]),
        ]);
    }
}
