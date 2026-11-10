<?php

namespace App\Http\Controllers;

use App\Models\AiAnalysis;
use App\Models\AiConnection;
use App\Models\BacklogProject;
use App\Models\Team;
use App\Services\AiPlanning;
use App\Services\ProjectAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Laravel\Ai\Ai;

class AiPlanningController extends Controller
{
    public function index(BacklogProject $project, ProjectAccess $access): JsonResponse
    {
        $access->authorize($project);
        $defaults = [];
        foreach (['openai', 'anthropic', 'gemini'] as $provider) {
            $defaults[$provider] = Ai::textProvider($provider)->cheapestTextModel();
        }

        return response()->json([
            'connections' => AiConnection::where('team_id', $project->team_id)->orderBy('id')->get()->map(fn (AiConnection $connection): array => ['id' => $connection->id, 'provider' => $connection->provider, 'model' => $connection->model, 'connected' => (bool) $connection->api_key]),
            'defaults' => $defaults,
            'analyses' => AiAnalysis::where('backlog_project_id', $project->id)->where('generation', $project->generation)->latest('id')->limit(10)->get(),
        ]);
    }

    public function connection(Request $request, BacklogProject $project, ProjectAccess $access): JsonResponse
    {
        $access->authorize($project, 'manageMembers');
        $input = $request->validate([
            'provider' => ['required', Rule::in(['openai', 'anthropic', 'gemini'])],
            'model' => ['required', 'string', 'max:120', 'regex:/^[a-zA-Z0-9_.:\/-]+$/'],
            'api_key' => ['required', 'string', 'min:10', 'max:2000', 'not_regex:/\s/'],
        ]);
        $connection = DB::transaction(function () use ($project, $input): AiConnection {
            Team::whereKey($project->team_id)->lockForUpdate()->firstOrFail();
            $connection = AiConnection::firstOrNew(['team_id' => $project->team_id, 'provider' => $input['provider']]);
            $connection->fill(['model' => $input['model'], 'api_key' => $input['api_key'], 'revision' => ($connection->revision ?? 0) + 1])->save();

            return $connection;
        });

        return response()->json(['id' => $connection->id, 'provider' => $connection->provider, 'model' => $connection->model, 'connected' => true]);
    }

    public function disconnect(BacklogProject $project, AiConnection $connection, ProjectAccess $access): JsonResponse
    {
        $access->authorize($project, 'manageMembers');
        abort_unless($connection->team_id === $project->team_id, 404);
        $connection->update(['api_key' => '', 'revision' => $connection->revision + 1]);

        return response()->json(['message' => 'AI key removed for this team. Saved proposals remain available.']);
    }

    public function store(Request $request, BacklogProject $project, ProjectAccess $access, AiPlanning $planning): JsonResponse
    {
        $access->authorize($project, 'edit');
        $input = $request->validate([
            'version' => ['required', 'integer', 'min:1'], 'connection_id' => ['required', 'integer'],
            'document_ids' => ['required', 'array', 'min:1', 'max:10'], 'document_ids.*' => ['required', 'string', 'max:100', 'distinct'],
            'parent_node_id' => ['nullable', 'string', 'max:100'], 'consent' => ['required', 'accepted'],
        ]);

        return response()->json($planning->start($project, $request->user(), $input), 202);
    }

    public function update(Request $request, BacklogProject $project, AiAnalysis $analysis, ProjectAccess $access, AiPlanning $planning): JsonResponse
    {
        $access->authorize($project, 'edit');
        abort_unless($analysis->backlog_project_id === $project->id && $analysis->generation === $project->generation, 404);
        $request->validate(['confirmation' => ['required', 'in:ADD_DRAFTS']]);

        return response()->json($planning->apply($analysis, $request->user()));
    }
}
