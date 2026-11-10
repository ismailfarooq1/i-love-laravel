<?php

namespace App\Http\Controllers;

use App\Jobs\SynchronizeGitHubIssues;
use App\Models\BacklogProject;
use App\Models\GitHubIssue;
use App\Models\GitHubPublication;
use App\Services\GitHubPublishing;
use App\Services\ProjectAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GitHubPublicationController extends Controller
{
    public function index(BacklogProject $project, ProjectAccess $access): JsonResponse
    {
        $access->authorize($project);

        return response()->json(['publications' => $project->publications()->latest('id')->limit(20)->get(), 'issues' => $project->githubIssues()->get()]);
    }

    public function store(Request $request, BacklogProject $project, ProjectAccess $access, GitHubPublishing $publishing): JsonResponse
    {
        $access->authorize($project, 'publish');
        $input = $request->validate(['version' => ['required', 'integer', 'min:1'], 'node_ids' => ['required', 'array', 'min:1', 'max:100'], 'node_ids.*' => ['required', 'string', 'max:100', 'distinct']]);

        return response()->json($publishing->prepare($project, $request->user(), $input['version'], $input['node_ids']), 201);
    }

    public function update(Request $request, BacklogProject $project, GitHubPublication $publication, ProjectAccess $access, GitHubPublishing $publishing): JsonResponse
    {
        $access->authorize($project, 'publish');
        abort_unless($publication->backlog_project_id === $project->id, 404);
        $request->validate(['confirmation' => ['required', 'in:PUBLISH_TO_GITHUB']]);

        return response()->json($publishing->approve($publication, $request->user()));
    }

    public function synchronize(BacklogProject $project, ProjectAccess $access): JsonResponse
    {
        $access->authorize($project, 'edit');
        abort_unless($project->github_connection_id, 422, 'Connect GitHub before synchronizing.');
        SynchronizeGitHubIssues::dispatch($project->id);

        return response()->json(['message' => 'Synchronization queued.'], 202);
    }

    public function retryCreation(Request $request, BacklogProject $project, GitHubIssue $issue, ProjectAccess $access): JsonResponse
    {
        $access->authorize($project, 'publish');
        abort_unless($issue->backlog_project_id === $project->id && $issue->generation === $project->generation, 404);
        $request->validate(['confirmation' => ['required', 'in:NO_ISSUE_CREATED']]);
        abort_if($issue->github_id !== null, 422, 'This issue is already mapped.');
        abort_if($project->publications()->whereIn('status', ['queued', 'publishing'])->exists(), 409, 'Wait for the active publication to finish.');
        $issue->update(['creation_status' => 'reserved']);

        return response()->json(['message' => 'Creation unlocked by explicit confirmation. Review GitHub carefully before retrying the failed batch.']);
    }
}
