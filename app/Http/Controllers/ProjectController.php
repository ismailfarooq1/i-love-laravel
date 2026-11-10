<?php

namespace App\Http\Controllers;

use App\Models\BacklogProject;
use App\Services\DemoScenario;
use App\Services\ProjectAccess;
use App\Services\TeamAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
}
