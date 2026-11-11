<?php

namespace App\Http\Controllers;

use App\Models\AiAnalysis;
use App\Models\AiConnection;
use App\Models\BacklogProject;
use App\Services\AiPlanning;
use App\Services\ChatGptAccess;
use App\Services\ProjectAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiPlanningController extends Controller
{
    public function index(Request $request, BacklogProject $project, ProjectAccess $access): JsonResponse
    {
        $access->authorize($project);

        return response()->json([
            'connections' => AiConnection::where('team_id', $project->team_id)->visibleTo($request->user())->with('owner')->orderBy('id')->get()->map(fn (AiConnection $connection): array => ['id' => $connection->id, 'revision' => $connection->revision, 'provider' => $connection->provider, 'model' => $connection->model, 'connected' => $connection->connected(), 'email' => $connection->credentials['email'] ?? 'ChatGPT account', 'usable' => $connection->usableBy($request->user()), 'owner_id' => $connection->user_id, 'owner_name' => $connection->owner?->name, 'shared_with_team' => $connection->shared_with_team, 'auth_type' => $connection->auth_type, 'can_manage' => $connection->user_id === $request->user()->id || ($connection->user_id === null && ($project->team->roleFor($request->user())?->canManage() ?? false))]),
            'analyses' => AiAnalysis::where('backlog_project_id', $project->id)->where('generation', $project->generation)->latest('id')->limit(10)->get(),
        ]);
    }

    public function disconnect(Request $request, BacklogProject $project, AiConnection $connection, ProjectAccess $access, ChatGptAccess $chatgpt): JsonResponse
    {
        $access->authorize($project);
        abort_unless($connection->team_id === $project->team_id && $connection->user_id === $request->user()->id && $connection->provider === 'chatgpt', 404);
        $confirmed = $chatgpt->disconnect($connection);

        return response()->json(['message' => $confirmed ? 'ChatGPT disconnected. Saved proposals remain.' : 'Disconnected locally. Remote revocation could not be confirmed; disconnect Tracework in ChatGPT settings too.']);
    }

    public function store(Request $request, BacklogProject $project, ProjectAccess $access, AiPlanning $planning): JsonResponse
    {
        $access->authorize($project, 'edit');
        $input = $request->validate([
            'connection_revision' => ['sometimes', 'integer', 'min:1'],
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
