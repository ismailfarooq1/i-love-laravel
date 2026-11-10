<?php

namespace App\Services;

use App\Models\GitHubConnection;
use App\Models\Team;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GitHubClient
{
    public function http(string $token): PendingRequest
    {
        return Http::baseUrl('https://api.github.com')->withToken($token)->acceptJson()
            ->withHeaders(['X-GitHub-Api-Version' => '2026-03-10', 'User-Agent' => 'Tracework'])
            ->connectTimeout(5)->timeout(15)->withOptions(['allow_redirects' => false]);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    public function request(GitHubConnection $connection, string $method, string $path, array $data = [], ?string $repositoryId = null, bool $asUser = false): array
    {
        $token = $asUser ? $this->userToken($connection) : $this->token($connection, $repositoryId);
        try {
            $response = $this->http($token)->send($method, $path, [strtoupper($method) === 'GET' ? 'query' : 'json' => $data]);
        } catch (ConnectionException) {
            throw new RuntimeException('GitHub did not confirm the request outcome. Reconcile any issue creation before retrying.');
        }
        if ($response->status() === 401 && $connection->mode === 'oauth') {
            $connection->update(['revoked' => true]);
        }
        if (! $response->successful()) {
            throw new RuntimeException('GitHub returned HTTP '.$response->status().'. Check repository access, connection permissions, or the rate limit before retrying.');
        }

        return $response->json() ?? [];
    }

    /** @return list<array<string, mixed>> */
    public function pages(GitHubConnection $connection, string $path, ?string $repositoryId = null, bool $asUser = false, ?string $key = null, array $query = []): array
    {
        $all = [];
        for ($page = 1; $page <= 100; $page++) {
            $response = $this->request($connection, 'GET', $path, [...$query, 'per_page' => 100, 'page' => $page], $repositoryId, $asUser);
            $items = $key ? ($response[$key] ?? []) : $response;
            $all = [...$all, ...$items];
            if (count($items) < 100) {
                return $all;
            }
        }
        throw new RuntimeException('GitHub pagination exceeded the safe limit. Narrow the repository selection or reconcile manually.');
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function exchange(array $data): array
    {
        try {
            $response = Http::acceptJson()->connectTimeout(5)->timeout(15)->withOptions(['allow_redirects' => false])->post('https://github.com/login/oauth/access_token', $data);
        } catch (\Throwable) {
            throw new RuntimeException('GitHub authorization could not be reached. Start the connection again.');
        }
        if (! $response->successful() || ! $response->json('access_token')) {
            throw new RuntimeException('GitHub declined authorization. Start the connection again.');
        }

        return $response->json();
    }

    public function userToken(GitHubConnection $connection): string
    {
        if ($connection->mode === 'oauth' && ! (Team::find($connection->team_id)?->roleFor($connection->user)?->canReview() ?? false)) {
            throw new RuntimeException('This personal publishing identity no longer has team publishing permission.');
        }
        if ($connection->revoked || ! $connection->access_token) {
            throw new RuntimeException('Reconnect this GitHub identity before continuing.');
        }
        if ($connection->expires_at && $connection->expires_at->lte(now()->addMinute())) {
            Cache::lock('github-refresh-'.$connection->id, 30)->block(5, function () use ($connection): void {
                $connection->refresh();
                if ($connection->expires_at && $connection->expires_at->gt(now()->addMinute())) {
                    return;
                }
                $prefix = $connection->mode === 'app' ? 'app' : 'oauth';
                $response = $this->exchange([
                    'client_id' => config('services.github.'.$prefix.'_client_id'), 'client_secret' => config('services.github.'.$prefix.'_client_secret'),
                    'grant_type' => 'refresh_token', 'refresh_token' => $connection->refresh_token,
                ]);
                $this->saveToken($connection, $response);
            });
        }

        return $connection->access_token;
    }

    /** @param array<string, mixed> $response */
    public function saveToken(GitHubConnection $connection, array $response): void
    {
        if (empty($response['access_token'])) {
            throw new RuntimeException('GitHub authorization could not be completed. Reconnect and try again.');
        }
        $connection->forceFill(['access_token' => $response['access_token'], 'refresh_token' => $response['refresh_token'] ?? null, 'expires_at' => isset($response['expires_in']) ? now()->addSeconds((int) $response['expires_in']) : null, 'revoked' => false])->save();
    }

    public function token(GitHubConnection $connection, ?string $repositoryId = null): string
    {
        if ($connection->revoked) {
            throw new RuntimeException('This GitHub connection has been disconnected.');
        }
        if ($connection->mode === 'oauth') {
            return $this->userToken($connection);
        }
        if (! $connection->installation_id) {
            throw new RuntimeException('Install and select the GitHub App before continuing.');
        }
        $key = 'github-installation-'.$connection->id.'-'.$connection->installation_id.'-'.($repositoryId ?? 'discovery');
        $encrypted = Cache::remember($key, 3000, function () use ($connection, $repositoryId): string {
            $response = $this->http($this->jwt())->post('/app/installations/'.$connection->installation_id.'/access_tokens', array_filter([
                'repository_ids' => $repositoryId ? [(int) $repositoryId] : null,
                'permissions' => ['issues' => 'write', 'metadata' => 'read'],
            ]));
            if (! $response->successful() || ! $response->json('token')) {
                throw new RuntimeException('GitHub App access is unavailable. Check the installation and app credentials.');
            }

            return Crypt::encryptString($response->json('token'));
        });

        return Crypt::decryptString($encrypted);
    }

    private function jwt(): string
    {
        $encode = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $header = $encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = $encode(json_encode(['iat' => time() - 60, 'exp' => time() + 540, 'iss' => config('services.github.app_id')], JSON_THROW_ON_ERROR));
        $key = str_replace('\\n', "\n", (string) config('services.github.private_key'));
        $private = openssl_pkey_get_private($key);
        if (! $private || ! openssl_sign($header.'.'.$payload, $signature, $private, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Configure a valid GitHub App private key.');
        }

        return $header.'.'.$payload.'.'.$encode($signature);
    }
}
