<?php

namespace App\Http\Controllers;

use App\Models\BacklogProject;
use App\Models\GitHubConnection;
use App\Models\GitHubIssue;
use App\Services\GitHubClient;
use App\Services\ProjectAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class GitHubConnectionController extends Controller
{
    public function index(Request $request, BacklogProject $project, ProjectAccess $access): JsonResponse
    {
        $access->authorize($project);

        return response()->json(GitHubConnection::where('team_id', $project->team_id)->where('user_id', $request->user()->id)->orderBy('id')->get());
    }

    public function store(Request $request, BacklogProject $project, ProjectAccess $access): JsonResponse
    {
        $access->authorize($project, 'publish');
        $data = $request->validate(['mode' => ['required', Rule::in(['app', 'oauth'])]]);
        if ($data['mode'] === 'app') {
            Gate::authorize('manageMembers', $project->team);
        }
        $clientId = config('services.github.'.$data['mode'].'_client_id');
        abort_unless($clientId && config('services.github.'.$data['mode'].'_client_secret'), 422, 'Configure the GitHub '.$data['mode'].' credentials first.');
        $state = Str::random(64);
        $verifier = Str::random(64);
        $request->session()->put('github.oauth.'.$state, ['project_id' => $project->id, 'team_id' => $project->team_id, 'user_id' => $request->user()->id, 'mode' => $data['mode'], 'verifier' => $verifier, 'expires' => time() + 600]);
        $query = ['client_id' => $clientId, 'redirect_uri' => route('github.callback'), 'state' => $state, 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256'];
        if ($data['mode'] === 'oauth') {
            $query['scope'] = 'repo admin:repo_hook read:user offline_access';
        }

        return response()->json(['url' => 'https://github.com/login/oauth/authorize?'.http_build_query($query)]);
    }

    public function callback(Request $request, ProjectAccess $access, GitHubClient $github): RedirectResponse
    {
        $request->validate(['state' => ['required', 'string', 'size:64'], 'code' => ['required', 'string', 'max:512']]);
        $pending = $request->session()->pull('github.oauth.'.$request->string('state')->toString());
        abort_unless($pending && $pending['expires'] > time() && $pending['user_id'] === $request->user()->id, 403, 'GitHub authorization expired or was already used.');
        $project = BacklogProject::where('team_id', $pending['team_id'])->findOrFail($pending['project_id']);
        $access->authorize($project, $pending['mode'] === 'app' ? 'manageMembers' : 'publish');
        $token = $github->exchange([
            'client_id' => config('services.github.'.$pending['mode'].'_client_id'), 'client_secret' => config('services.github.'.$pending['mode'].'_client_secret'),
            'code' => $request->input('code'), 'redirect_uri' => route('github.callback'), 'code_verifier' => $pending['verifier'],
        ]);
        abort_unless(! empty($token['access_token']), 422, 'GitHub declined authorization. Start the connection again.');
        $identityResponse = $github->http($token['access_token'])->get('/user');
        abort_unless($identityResponse->successful() && $identityResponse->json('id'), 422, 'Could not verify the GitHub account.');
        $connection = GitHubConnection::firstOrNew(['team_id' => $project->team_id, 'user_id' => $request->user()->id, 'mode' => $pending['mode'], 'slot' => 'identity']);
        if ($connection->exists && $connection->github_user_id !== (string) $identityResponse->json('id')) {
            abort_if($connection->installation_id || BacklogProject::where('github_connection_id', $connection->id)->exists() || GitHubIssue::where('github_connection_id', $connection->id)->exists(), 422, 'Disconnect the existing identity before changing GitHub accounts.');
        }
        $connection->fill(['github_user_id' => (string) $identityResponse->json('id'), 'login' => $identityResponse->json('login')]);
        $github->saveToken($connection, $token);
        $request->session()->put('team_id', $project->team_id);
        $request->session()->put('project_id.'.$project->team_id, $project->id);

        return redirect()->route('home', ['settings' => 'github']);
    }

    public function install(Request $request, BacklogProject $project, GitHubConnection $connection, ProjectAccess $access): JsonResponse
    {
        $access->authorize($project, 'manageMembers');
        abort_unless($connection->team_id === $project->team_id && $connection->user_id === $request->user()->id && $connection->mode === 'app', 404);
        abort_unless(config('services.github.app_slug'), 422, 'Configure the GitHub App slug.');
        $state = Str::random(64);
        $request->session()->put('github.install.'.$state, ['project_id' => $project->id, 'connection_id' => $connection->id, 'user_id' => $request->user()->id, 'expires' => time() + 600]);

        return response()->json(['url' => 'https://github.com/apps/'.rawurlencode(config('services.github.app_slug')).'/installations/new?'.http_build_query(['state' => $state])]);
    }

    public function installed(Request $request, ProjectAccess $access, GitHubClient $github): RedirectResponse
    {
        $data = $request->validate(['state' => ['required', 'string', 'size:64'], 'installation_id' => ['required', 'integer', 'min:1']]);
        $pending = $request->session()->pull('github.install.'.$data['state']);
        abort_unless($pending && $pending['expires'] > time() && $pending['user_id'] === $request->user()->id, 403);
        $project = BacklogProject::findOrFail($pending['project_id']);
        $access->authorize($project, 'manageMembers');
        $connection = GitHubConnection::where('team_id', $project->team_id)->where('user_id', $request->user()->id)->findOrFail($pending['connection_id']);
        $installations = $github->pages($connection, '/user/installations', asUser: true, key: 'installations');
        $installation = collect($installations)->first(fn (array $item): bool => (string) $item['id'] === (string) $data['installation_id']);
        abort_unless($installation && (string) ($installation['app_id'] ?? '') === (string) config('services.github.app_id') && ($installation['permissions']['issues'] ?? null) === 'write', 403, 'This installation is not available to the connecting GitHub user with Issues write access.');
        $installed = GitHubConnection::firstOrNew(['team_id' => $project->team_id, 'user_id' => $request->user()->id, 'mode' => 'app', 'slot' => 'installation-'.$data['installation_id']]);
        $installed->fill(['github_user_id' => $connection->github_user_id, 'login' => $connection->login, 'access_token' => $connection->access_token, 'refresh_token' => $connection->refresh_token, 'expires_at' => $connection->expires_at, 'installation_id' => (string) $data['installation_id'], 'revoked' => false])->save();
        $request->session()->put('team_id', $project->team_id);
        $request->session()->put('project_id.'.$project->team_id, $project->id);

        return redirect()->route('home', ['settings' => 'github']);
    }

    public function repositories(Request $request, BacklogProject $project, GitHubConnection $connection, ProjectAccess $access, GitHubClient $github): JsonResponse
    {
        $access->authorize($project, 'manageMembers');
        abort_unless($connection->team_id === $project->team_id && $connection->user_id === $request->user()->id, 404);
        $repos = $connection->mode === 'app' ? $github->pages($connection, '/installation/repositories', key: 'repositories') : $github->pages($connection, '/user/repos', asUser: true, query: ['affiliation' => 'owner,organization_member,collaborator']);

        return response()->json(collect($repos)->filter(fn (array $repo): bool => ! ($repo['archived'] ?? false) && ($repo['has_issues'] ?? false))->map(fn (array $repo): array => ['id' => (string) $repo['id'], 'full_name' => $repo['full_name'], 'private' => $repo['private']])->values());
    }

    public function update(Request $request, BacklogProject $project, ProjectAccess $access, GitHubClient $github): JsonResponse
    {
        $access->authorize($project, 'manageMembers');
        $data = $request->validate(['connection_id' => ['required', 'integer'], 'repository_id' => ['required', 'integer', 'min:1'], 'confirm_sync' => ['accepted']]);
        $connection = GitHubConnection::where('team_id', $project->team_id)->where('user_id', $request->user()->id)->findOrFail($data['connection_id']);
        abort_if($project->repository_id && $project->repository_id !== (string) $data['repository_id'] && ($project->githubIssues()->exists() || $project->publications()->whereIn('status', ['queued', 'publishing'])->exists()), 422, 'Create a new project to publish to a different repository. Existing issue mappings must remain intact.');
        $repo = $github->request($connection, 'GET', '/repositories/'.$data['repository_id'], asUser: true);
        abort_unless(($repo['permissions']['admin'] ?? false) && ($repo['has_issues'] ?? false) && ! ($repo['archived'] ?? false), 403, 'Repository administrator access and enabled issues are required to configure synchronization.');
        if ($connection->mode === 'app') {
            $allowed = $github->pages($connection, '/installation/repositories', key: 'repositories');
            abort_unless(collect($allowed)->contains(fn (array $item): bool => (string) $item['id'] === (string) $repo['id']), 403);
        }
        abort_unless(config('services.github.webhook_secret'), 422, 'Configure the GitHub webhook secret before enabling synchronization.');
        $hookId = $project->webhook_id;
        if ($connection->mode === 'oauth') {
            $hooks = $github->pages($connection, '/repos/'.$repo['full_name'].'/hooks', asUser: true);
            $hook = collect($hooks)->first(fn (array $item): bool => ($item['config']['url'] ?? null) === route('github.webhook'));
            $payload = ['active' => true, 'events' => ['issues'], 'config' => ['url' => route('github.webhook'), 'content_type' => 'json', 'secret' => config('services.github.webhook_secret'), 'insecure_ssl' => '0']];
            $hook = $github->request($connection, $hook ? 'PATCH' : 'POST', '/repos/'.$repo['full_name'].'/hooks'.($hook ? '/'.$hook['id'] : ''), $payload, asUser: true);
            $hookId = (string) $hook['id'];
        }
        DB::transaction(function () use ($project, $connection, $repo, $hookId, $access): void {
            $locked = BacklogProject::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $access->authorize($locked, 'manageMembers');
            abort_if($locked->repository_id && $locked->repository_id !== (string) $repo['id'] && $locked->githubIssues()->exists(), 409, 'Repository changed while configuring. Reload and review again.');
            $locked->update(['connection_mode' => $connection->mode, 'github_connection_id' => $connection->id, 'repository_id' => (string) $repo['id'], 'repository_full_name' => $repo['full_name'], 'repository_private' => $repo['private'], 'webhook_id' => $hookId]);
        });

        return response()->json(['message' => 'Repository connected. Two-way completion synchronization is enabled.']);
    }

    public function destroy(Request $request, BacklogProject $project, ProjectAccess $access): JsonResponse
    {
        $access->authorize($project, 'manageMembers');
        $project->update(['github_connection_id' => null]);

        return response()->json(['message' => 'Project disconnected. Existing GitHub issues were preserved; publishing and synchronization are paused.']);
    }
}
