<?php

namespace App\Http\Controllers;

use App\Http\Requests\WorkspaceCommandRequest;
use App\Models\BacklogProject;
use App\Services\BacklogWorkflow;
use App\Services\TeamAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class WorkspaceController extends Controller
{
    public function show(Request $request, TeamAccess $access): JsonResponse
    {
        $team = $access->current($request);
        $project = BacklogProject::where('team_id', $team->id)->first();

        return response()->json($project ? $project->toArray() : ['state' => null, 'version' => null]);
    }

    public function store(Request $request, BacklogWorkflow $workflow, TeamAccess $access): JsonResponse
    {
        $team = $access->current($request);
        Gate::authorize('reset', $team);
        $input = $request->validate(['confirmation' => ['required', 'in:RESET'], 'version' => ['nullable', 'integer', 'min:1'], 'example' => ['sometimes', 'boolean']]);

        return response()->json($workflow->reset($team, $request->user(), $input['version'] ?? null, $input['example'] ?? true));
    }

    public function update(WorkspaceCommandRequest $request, BacklogWorkflow $workflow, TeamAccess $access): JsonResponse
    {
        return response()->json($workflow->command($access->current($request), $request->user(), $request->validated()));
    }

    public function document(Request $request, BacklogWorkflow $workflow, TeamAccess $access): JsonResponse
    {
        $team = $access->current($request);
        Gate::authorize('edit', $team);
        $input = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'file' => ['required', 'file', 'max:100', 'extensions:txt,md', 'mimetypes:text/plain,text/markdown,text/x-markdown'],
            'document_id' => ['nullable', 'string', 'max:100'],
        ]);
        $file = $request->file('file');
        $content = $file->get();
        if (! mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0") || trim($content) === '') {
            throw ValidationException::withMessages(['file' => 'Upload a non-empty UTF-8 text or Markdown document.']);
        }

        return response()->json($workflow->command($team, $request->user(), [
            'version' => (int) $input['version'], 'action' => 'document',
            'name' => $file->getClientOriginalName(), 'content' => $content,
            'document_id' => $input['document_id'] ?? null,
        ]));
    }
}
