<?php

namespace App\Services;

use App\Models\AiConnection;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ChatGptAccess
{
    public const ISSUER = 'https://auth.openai.com';

    public const RESOURCE = 'https://api.openai.com/v1';

    public const SCOPES = 'openid profile email offline_access resource.invoke chatgpt.tokens.use.direct';

    public function http(): PendingRequest
    {
        return Http::acceptJson()->connectTimeout(5)->timeout(15)->withOptions(['allow_redirects' => false]);
    }

    public function callbackUri(): string
    {
        return 'http://127.0.0.1:'.(int) config('services.chatgpt.callback_port', 8000).'/auth/chatgpt/callback';
    }

    public function hostId(): string
    {
        return Cache::lock('chatgpt-host-id', 10)->block(3, function (): string {
            $file = storage_path('app/private/chatgpt-host-id');
            if (! is_file($file)) {
                if (! is_dir(dirname($file))) {
                    mkdir(dirname($file), 0700, true);
                }
                if (file_put_contents($file, 'urn:uuid:'.Str::uuid(), LOCK_EX) === false) {
                    throw new RuntimeException('Could not save the ChatGPT host identity.');
                }
                chmod($file, 0600);
            }
            $id = trim(file_get_contents($file));
            if (! preg_match('/^urn:uuid:[a-f0-9-]{36}$/', $id)) {
                throw new RuntimeException('The saved ChatGPT host identity is invalid.');
            }

            return $id;
        });
    }

    /** @param array<string, string> $fields
     * @return array<string, mixed>
     */
    public function grant(array $fields): array
    {
        $response = $this->http()->asForm()->post(self::ISSUER.'/api/accounts/oauth/token', [...$fields, 'resource' => self::RESOURCE]);
        if (! $response->successful()) {
            throw new RuntimeException('ChatGPT authorization could not be renewed. Reconnect your account.', in_array($response->json('error'), ['invalid_grant', 'invalid_refresh_token', 'refresh_token_reused', 'refresh_token_expired', 'refresh_token_invalidated'], true) ? 410 : 503);
        }

        return $response->json();
    }

    /** @return array{subject: string, email: string} */
    public function identity(string $token, string $clientId, ?string $nonce = null, ?string $subject = null): array
    {
        $response = $this->http()->get(self::ISSUER.'/.well-known/jwks.json');
        if (! $response->successful()) {
            throw new RuntimeException('ChatGPT identity could not be verified.');
        }
        $keys = array_values(array_filter($response->json('keys', []), fn (array $key): bool => ($key['kty'] ?? '') === 'RSA' && ($key['alg'] ?? 'RS256') === 'RS256' && ($key['use'] ?? 'sig') === 'sig'));
        $claims = (array) JWT::decode($token, JWK::parseKeySet(['keys' => $keys], 'RS256'));
        $audiences = is_array($claims['aud'] ?? null) ? $claims['aud'] : [$claims['aud'] ?? null];
        if (($claims['iss'] ?? '') !== self::ISSUER || ! in_array($clientId, $audiences, true)
            || ! is_int($claims['exp'] ?? null) || $claims['exp'] <= time()
            || ! is_int($claims['iat'] ?? null) || $claims['iat'] > time() + 5
            || ! is_string($claims['sub'] ?? null) || $claims['sub'] === ''
            || ($nonce !== null && (! is_string($claims['nonce'] ?? null) || ! hash_equals($nonce, $claims['nonce'])))
            || ($subject !== null && $claims['sub'] !== $subject)
            || (isset($claims['azp']) && $claims['azp'] !== $clientId)
            || (count($audiences) > 1 && ($claims['azp'] ?? null) !== $clientId)) {
            throw new RuntimeException('ChatGPT identity could not be verified.');
        }

        return ['subject' => $claims['sub'], 'email' => is_string($claims['email'] ?? null) ? $claims['email'] : 'ChatGPT account'];
    }

    /** @param array<string, mixed> $body
     * @param  array<string, mixed>  $previous
     * @return array<string, mixed>
     */
    public function credentials(array $body, array $previous = []): array
    {
        $scopes = isset($body['scope']) ? preg_split('/\s+/', $body['scope']) : ($previous['scopes'] ?? []);
        foreach (['access_token', 'refresh_token'] as $field) {
            if (! is_string($body[$field] ?? null) || $body[$field] === '' || preg_match('/\s/', $body[$field])) {
                throw new RuntimeException('ChatGPT did not return usable credentials. Reconnect.');
            }
        }
        if (array_diff(explode(' ', self::SCOPES), $scopes) !== [] || strtolower($body['token_type'] ?? '') !== 'bearer' || ! is_numeric($body['expires_in'] ?? null) || $body['expires_in'] <= 0 || str_starts_with($body['access_token'], 'sk-')) {
            throw new RuntimeException('ChatGPT plan permission is missing. Reconnect and authorize plan usage.');
        }

        return [...$previous, 'access_token' => $body['access_token'], 'refresh_token' => $body['refresh_token'], 'id_token' => $body['id_token'] ?? $previous['id_token'] ?? null, 'scopes' => $scopes, 'expires_at' => time() + (int) $body['expires_in']];
    }

    public function token(AiConnection $connection): string
    {
        return Cache::lock('chatgpt-connection-'.$connection->id, 60)->block(3, function () use ($connection): string {
            $current = $connection->fresh();
            if (! $current || $current->revision !== $connection->revision || ! $current->connected()) {
                throw new RuntimeException('ChatGPT connection changed. Reconnect and start again.');
            }
            $credentials = $current->credentials;
            if (($credentials['expires_at'] ?? 0) <= time() + 60) {
                try {
                    $body = $this->grant(['grant_type' => 'refresh_token', 'client_id' => $credentials['client_id'], 'refresh_token' => $credentials['refresh_token']]);
                } catch (RuntimeException $error) {
                    if ($error->getCode() === 410) {
                        $current->update(['credentials' => array_intersect_key($credentials, array_flip(['client_id', 'subject', 'email'])), 'revision' => $current->revision + 1]);
                    }
                    throw $error;
                }
                try {
                    if (isset($body['id_token'])) {
                        $this->identity($body['id_token'], $credentials['client_id'], subject: $credentials['subject']);
                    }
                    $credentials = $this->credentials($body, $credentials);
                    $current->update(['credentials' => $credentials]);
                } catch (Throwable) {
                    $current->update(['credentials' => array_intersect_key($credentials, array_flip(['client_id', 'subject', 'email'])), 'revision' => $current->revision + 1]);
                    throw new RuntimeException('ChatGPT credentials could not be renewed safely. Reconnect your account.');
                }
            }

            return $credentials['access_token'];
        });
    }

    /** @return list<array{id: string, name: string}> */
    public function models(AiConnection $connection): array
    {
        $response = $this->http()->withToken($this->token($connection))->get(self::RESOURCE.'/models');
        if (! $response->successful() || ! is_array($response->json('models'))) {
            throw new RuntimeException('ChatGPT models could not be loaded. Check your account access and try again.');
        }

        return collect($response->json('models'))->filter(fn (array $model): bool => ($model['visibility'] ?? '') === 'list' && is_string($model['slug'] ?? null))
            ->map(fn (array $model): array => ['id' => $model['slug'], 'name' => $model['display_name'] ?? $model['slug']])->values()->all();
    }

    public function disconnect(AiConnection $connection): bool
    {
        return Cache::lock('chatgpt-connection-'.$connection->id, 60)->block(3, function () use ($connection): bool {
            $connection->refresh();
            $credentials = $connection->credentials ?? [];
            $confirmed = empty($credentials['refresh_token']);
            try {
                if (! $confirmed) {
                    $discovery = $this->http()->get(self::ISSUER.'/.well-known/openid-configuration');
                    $endpoint = $discovery->json('revocation_endpoint');
                    $parts = is_string($endpoint) ? parse_url($endpoint) : [];
                    if (! $discovery->successful() || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'auth.openai.com' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])) {
                        throw new RuntimeException;
                    }
                    $confirmed = $this->http()->asForm()->post($endpoint, ['token' => $credentials['refresh_token'], 'token_type_hint' => 'refresh_token', 'client_id' => $credentials['client_id']])->status() === 200;
                }
            } catch (Throwable) {
                $confirmed = false;
            }
            $connection->update(['credentials' => array_intersect_key($credentials, array_flip(['client_id', 'subject', 'email'])), 'revision' => $connection->revision + 1]);

            return $confirmed;
        });
    }
}
