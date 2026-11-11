<?php

namespace App\Http\Controllers;

use App\Models\AiConnection;
use App\Models\BacklogProject;
use App\Models\Team;
use App\Services\ChatGptAccess;
use App\Services\ProjectAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ChatGptConnectionController extends Controller
{
    public function store(Request $request, BacklogProject $project, ProjectAccess $access, ChatGptAccess $chatgpt): JsonResponse
    {
        $access->authorize($project, 'edit');
        $input = $request->validate(['connection_id' => ['nullable', 'integer'], 'local_confirmation' => ['required', 'accepted']]);
        $connection = isset($input['connection_id']) ? AiConnection::where('team_id', $project->team_id)->visibleTo($request->user())->findOrFail($input['connection_id']) : null;
        $origin = rtrim(config('app.url'), '/');
        abort_unless($request->getSchemeAndHttpHost() === $origin, 422, 'Open Tracework at its configured application address before connecting ChatGPT.');
        $state = Str::random(64);
        $verifier = Str::random(64);
        $nonce = Str::random(64);
        $previous = $request->session()->get('chatgpt.attempt');
        if ($previous) {
            Cache::forget('chatgpt-pending-'.$previous);
            Cache::forget('chatgpt-result-'.$previous);
        }
        $browserSecret = Str::random(64);
        $request->session()->put('chatgpt.attempt', $state);
        $request->session()->put('chatgpt.browser_secret', $browserSecret);
        $pending = ['user_id' => $request->user()->id, 'project_id' => $project->id, 'team_id' => $project->team_id, 'connection_id' => $connection?->id, 'revision' => $connection?->revision,
            'client_id' => $connection?->credentials['client_id'] ?? null, 'subject' => $connection?->credentials['subject'] ?? null,
            'session' => hash('sha256', $browserSecret), 'verifier' => $verifier, 'nonce' => $nonce, 'redirect_uri' => $chatgpt->callbackUri(), 'return_uri' => $origin.route('chatgpt.finish', absolute: false)];
        Cache::put('chatgpt-pending-'.$state, Crypt::encryptString(json_encode($pending, JSON_THROW_ON_ERROR)), 600);
        $parameters = ['client_id' => $pending['client_id'] ?? 'dynamic_agent_client', 'ext_agent_host_id' => $chatgpt->hostId(), 'response_type' => 'code', 'redirect_uri' => $pending['redirect_uri'], 'scope' => ChatGptAccess::SCOPES, 'resource' => ChatGptAccess::RESOURCE,
            'state' => $state, 'nonce' => $nonce, 'code_challenge_method' => 'S256', 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
        if (! $pending['client_id']) {
            $parameters['agent_name_hint'] = 'Tracework';
        }

        return response()->json(['url' => ChatGptAccess::ISSUER.'/api/accounts/authorize?'.http_build_query($parameters)])->header('Cache-Control', 'no-store');
    }

    public function callback(Request $request, ChatGptAccess $chatgpt): RedirectResponse
    {
        abort_unless($request->getSchemeAndHttpHost().$request->getPathInfo() === $chatgpt->callbackUri()
            && in_array($request->server('REMOTE_ADDR'), ['127.0.0.1', '::1'], true)
            && ! $request->hasHeader('X-Forwarded-For') && ! $request->hasHeader('X-Forwarded-Proto'), 403);
        $state = $request->query('state');
        abort_unless(is_string($state) && preg_match('/^[a-zA-Z0-9]{64}$/', $state), 403);
        foreach (['state', 'code', 'client_id', 'error'] as $key) {
            $count = 0;
            foreach (explode('&', $request->server('QUERY_STRING', '')) as $part) {
                if (urldecode(explode('=', $part, 2)[0]) === $key) {
                    $count++;
                }
            }
            abort_if($count > 1, 403);
        }

        return Cache::lock('chatgpt-callback-'.$state, 60)->block(3, function () use ($request, $chatgpt, $state): RedirectResponse {
            $encrypted = Cache::pull('chatgpt-pending-'.$state);
            abort_unless($encrypted, 403, 'This connection attempt expired or was already used.');
            $pending = json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);
            $result = ['pending' => $pending, 'error' => true];
            try {
                $clientId = $request->query('client_id', $pending['client_id']);
                $code = $request->query('code');
                if ($request->has('error') || ! is_string($clientId) || ! preg_match('/^oaiapp_[a-zA-Z0-9_-]{1,500}$/', $clientId)
                    || ($pending['client_id'] && $clientId !== $pending['client_id']) || ! is_string($code) || $code === '' || strlen($code) > 4096) {
                    throw new \RuntimeException;
                }
                $body = $chatgpt->grant(['grant_type' => 'authorization_code', 'client_id' => $clientId, 'code' => $code, 'code_verifier' => $pending['verifier'], 'redirect_uri' => $pending['redirect_uri']]);
                $identity = $chatgpt->identity($body['id_token'], $clientId, $pending['nonce'], $pending['subject']);
                $result = ['pending' => $pending, 'error' => false, 'credentials' => $chatgpt->credentials($body, ['client_id' => $clientId, ...$identity])];
            } catch (Throwable) {
                // OAuth bodies and exceptions may contain credentials; only a fixed outcome crosses the boundary.
            }
            unset($result['pending']['verifier'], $result['pending']['nonce']);
            Cache::put('chatgpt-result-'.$state, Crypt::encryptString(json_encode($result, JSON_THROW_ON_ERROR)), 300);

            return redirect()->away($pending['return_uri'])->withHeaders(['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
        });
    }

    public function finish(Request $request, ProjectAccess $access): RedirectResponse
    {
        $state = $request->session()->pull('chatgpt.attempt');
        abort_unless(is_string($state), 403, 'Return using the browser session that started ChatGPT sign-in.');

        return Cache::lock('chatgpt-finish-'.$state, 15)->block(3, function () use ($state, $request, $access): RedirectResponse {
            $encrypted = Cache::pull('chatgpt-result-'.$state);
            abort_unless($encrypted, 403, 'The connection attempt expired. Start again.');
            $result = json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);
            $pending = $result['pending'];
            abort_unless($pending['user_id'] === $request->user()->id && hash_equals($pending['session'], hash('sha256', (string) $request->session()->pull('chatgpt.browser_secret'))), 403);
            $project = BacklogProject::where('team_id', $pending['team_id'])->findOrFail($pending['project_id']);
            $access->authorize($project, 'edit');
            if ($result['error']) {
                return redirect()->route('home', ['view' => 'ai', 'ai_error' => 'authorization_failed']);
            }
            $save = function () use ($result, $pending, $request): bool {
                return DB::transaction(function () use ($result, $pending, $request): bool {
                    Team::whereKey($pending['team_id'])->lockForUpdate()->firstOrFail();
                    Gate::authorize('edit', Team::findOrFail($pending['team_id']));
                    $connection = $pending['connection_id'] ? AiConnection::where('team_id', $pending['team_id'])->visibleTo($request->user())->lockForUpdate()->findOrFail($pending['connection_id'])
                        : AiConnection::firstOrNew(['team_id' => $pending['team_id'], 'provider' => 'chatgpt', 'slot' => 'user-'.$request->user()->id.'-'.hash('sha256', $result['credentials']['client_id'])]);
                    abort_if($pending['connection_id'] && $connection->revision !== $pending['revision'], 409, 'The connection changed during sign-in. Start again.');
                    $firstConnection = ! $connection->exists;
                    $connection->fill(['user_id' => $request->user()->id, 'model' => $connection->model ?? '', 'api_key' => '', 'auth_type' => 'oauth', 'shared_with_team' => false, 'credentials' => $result['credentials'], 'revision' => ($connection->revision ?? 0) + 1])->save();

                    return $firstConnection;
                });
            };
            $firstConnection = $pending['connection_id'] ? Cache::lock('chatgpt-connection-'.$pending['connection_id'], 60)->block(3, $save) : $save();
            $request->session()->put('team_id', $project->team_id);
            $request->session()->put('project_id.'.$project->team_id, $project->id);

            return redirect()->route('home', ['view' => 'ai', $firstConnection ? 'ai_connected' : 'ai_reconnected' => 'chatgpt']);
        });
    }

    public function models(Request $request, BacklogProject $project, AiConnection $connection, ProjectAccess $access, ChatGptAccess $chatgpt): JsonResponse
    {
        $access->authorize($project, 'edit');
        abort_unless($connection->team_id === $project->team_id && $connection->user_id === $request->user()->id && $connection->provider === 'chatgpt', 404);
        try {
            return response()->json($chatgpt->models($connection));
        } catch (Throwable) {
            throw ValidationException::withMessages(['chatgpt' => 'Could not load ChatGPT models. Reconnect or try again later.']);
        }
    }

    public function update(Request $request, BacklogProject $project, AiConnection $connection, ProjectAccess $access, ChatGptAccess $chatgpt): JsonResponse
    {
        $access->authorize($project, 'edit');
        abort_unless($connection->team_id === $project->team_id && $connection->user_id === $request->user()->id && $connection->provider === 'chatgpt', 404);
        $input = $request->validate(['model' => ['required', 'string', 'max:120'], 'revision' => ['required', 'integer']]);
        try {
            $models = $chatgpt->models($connection);
        } catch (Throwable) {
            throw ValidationException::withMessages(['chatgpt' => 'Could not verify this model. Reconnect or try again later.']);
        }
        abort_unless(collect($models)->contains('id', $input['model']), 422, 'Choose a model available to this ChatGPT account.');
        $updated = AiConnection::whereKey($connection->id)->where('revision', $input['revision'])->update(['model' => $input['model'], 'revision' => $input['revision'] + 1]);
        abort_unless($updated, 409, 'This connection changed. Reload before choosing a model.');

        return response()->json(['message' => 'ChatGPT model selected.']);
    }
}
