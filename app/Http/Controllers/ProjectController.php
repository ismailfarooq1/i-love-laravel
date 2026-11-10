<?php

namespace App\Http\Controllers;

use App\Models\BacklogProject;
use App\Models\Team;
use App\Services\DemoScenario;
use App\Services\ProjectAccess;
use App\Services\TeamAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    public function index(Request $request, TeamAccess $teams, ProjectAccess $projects): JsonResponse
    {
        $team = $teams->current($request);
        $project = $projects->current($request, $team);

        return response()->json(['projects' => BacklogProject::where('team_id', $team->id)->orderBy('id')->get(['id', 'name', 'description', 'connection_mode', 'repository_full_name']), 'project' => $project?->load('githubConnection'), 'github_configured' => ['app' => filled(config('services.github.app_client_id')) && filled(config('services.github.app_id')) && filled(config('services.github.private_key')) && filled(config('services.github.app_client_secret')) && filled(config('services.github.app_slug')), 'oauth' => filled(config('services.github.oauth_client_id')) && filled(config('services.github.oauth_client_secret'))]]);
    }

    public function store(Request $request, TeamAccess $teams, DemoScenario $scenario): JsonResponse
    {
        $team = $teams->current($request);
        Gate::authorize('manageMembers', $team);
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:3000'], 'connection_mode' => ['required', Rule::in(['app', 'oauth', 'later'])]]);
        $state = $scenario->initial();
        $state['name'] = $data['name'];
        $state['documents'] = [];
        $project = BacklogProject::create([...$data, 'workspace' => (string) Str::uuid(), 'team_id' => $team->id, 'version' => 1, 'state' => $state]);
        $request->session()->put('project_id.'.$team->id, $project->id);

        return response()->json($project, 201);
    }

    public function update(Request $request, BacklogProject $project, ProjectAccess $access): JsonResponse
    {
        $access->authorize($project);
        $request->session()->put('team_id', $project->team_id);
        $request->session()->put('project_id.'.$project->team_id, $project->id);

        return response()->json(['project_id' => $project->id]);
    }

    public function destroy(Request $request, BacklogProject $project, ProjectAccess $access): JsonResponse
    {
        $access->authorize($project, 'manageMembers');
        $input = $request->validate(['confirmation' => ['required', 'string', 'max:100'], 'version' => ['required', 'integer', 'min:1']]);
        $deleted = Cache::lock('github-project-'.$project->id, 180)->get(function () use ($request, $project, $access, $input): bool {
            return DB::transaction(function () use ($request, $project, $access, $input): bool {
                Team::whereKey($project->team_id)->lockForUpdate()->firstOrFail();
                $locked = BacklogProject::whereKey($project->id)->lockForUpdate()->firstOrFail();
                $access->authorize($locked, 'manageMembers');
                abort_unless($input['confirmation'] === $locked->name, 422, 'Type the project name exactly to confirm permanent deletion.');
                abort_if($input['version'] !== $locked->version, 409, 'The project changed. Refresh and review it before deleting.');
                abort_if($locked->publications()->whereIn('status', ['queued', 'publishing'])->exists(), 409, 'Wait for the current GitHub publication to finish before deleting this project.');
                $locked->delete();
                if ((string) $request->session()->get('project_id.'.$locked->team_id) === (string) $locked->id) {
                    $request->session()->forget('project_id.'.$locked->team_id);
                }

                return true;
            });
        });
        abort_unless($deleted, 409, 'GitHub publishing or synchronization is running. Please try deleting again when it finishes.');

        return response()->json(['message' => 'Project permanently deleted.']);
    }
}
