<?php

namespace App\Services;

use App\Jobs\SynchronizeGitHubIssues;
use App\Models\BacklogProject;
use App\Models\GitHubConnection;
use App\Models\GitHubIssue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class GitHubSynchronization
{
    public function __construct(private GitHubClient $github, private BacklogWorkflow $workflow) {}

    public static function queueLocal(BacklogProject $project, bool $dispatch = true): void
    {
        if (! $project->github_connection_id) {
            return;
        }
        foreach ($project->githubIssues()->whereNotNull('github_id')->get() as $issue) {
            $node = $project->state['nodes'][$issue->node_id] ?? null;
            if (! $node) {
                continue;
            }
            $desired = $node['status'] === 'approved' && ($node['completed_revision'] ?? null) === $node['revision'] ? 'closed' : 'open';
            $pending = $issue->remote_state === $desired ? null : $desired;
            $issue->update(['desired_state' => $desired, 'pending_state' => $pending, 'sync_status' => $pending ? 'pending' : 'synced']);
        }
        if ($dispatch && $project->githubIssues()->whereNotNull('pending_state')->exists()) {
            SynchronizeGitHubIssues::dispatch($project->id)->afterCommit();
        }
    }

    public function synchronize(int $projectId, string $actor = 'repository user'): void
    {
        Cache::lock('github-project-'.$projectId, 180)->block(5, function () use ($projectId, $actor): void {
            $project = BacklogProject::find($projectId);
            if (! $project || ! $project->github_connection_id) {
                return;
            }
            $remote = [];
            foreach ($project->githubIssues()->whereNotNull('github_id')->get() as $issue) {
                try {
                    $connection = GitHubConnection::findOrFail($issue->github_connection_id);
                    $response = $this->github->request($connection, 'GET', '/repos/'.$project->repository_full_name.'/issues/'.$issue->number, repositoryId: $project->repository_id);
                    if ((string) $response['id'] !== $issue->github_id) {
                        throw new RuntimeException('GitHub issue identity does not match the saved mapping.');
                    }
                    $remote[$issue->id] = $response;
                } catch (\Throwable $error) {
                    $issue->update(['sync_status' => 'error', 'error' => 'Could not read GitHub issue. Check the connection and retry synchronization.']);
                    throw new RuntimeException('Could not read GitHub issue. Check the connection and retry synchronization.');
                }
            }
            $closed = [];
            foreach ($remote as $id => $response) {
                $issue = GitHubIssue::findOrFail($id);
                if ($issue->pending_state !== null || $issue->remote_state === $response['state']) {
                    continue;
                }
                if ($response['state'] === 'closed') {
                    $closed[$id] = $response;
                } else {
                    $this->workflow->applyGitHubState($project, $issue->node_id, false, $actor);
                    $issue->update(['remote_state' => 'open']);
                    self::queueLocal($project->fresh(), false);
                }
            }
            do {
                $progress = false;
                foreach ($closed as $id => $response) {
                    $issue = GitHubIssue::findOrFail($id);
                    if ($issue->pending_state !== null) {
                        unset($closed[$id]);

                        continue;
                    }
                    if ($this->workflow->applyGitHubState($project, $issue->node_id, true, $response['closed_by']['login'] ?? 'repository user')) {
                        $issue->update(['remote_state' => 'closed']);
                        self::queueLocal($project->fresh(), false);
                        unset($closed[$id]);
                        $progress = true;
                    }
                }
            } while ($progress && $closed !== []);
            foreach ($closed as $id => $response) {
                $issue = GitHubIssue::findOrFail($id);
                $issue->update(['remote_state' => 'closed', 'desired_state' => 'open', 'pending_state' => 'open', 'sync_status' => 'pending', 'error' => 'GitHub closed this issue before its approved children or prerequisites were complete. Tracework will reopen it.']);
                DB::transaction(function () use ($projectId, $issue): void {
                    $project = BacklogProject::whereKey($projectId)->lockForUpdate()->firstOrFail();
                    $state = $project->state;
                    $state['history'][] = ['action' => 'github_invalid_close_reopened', 'node_id' => $issue->node_id, 'revision' => $state['nodes'][$issue->node_id]['revision'], 'actor' => 'Tracework synchronization', 'actor_id' => null, 'reason' => 'Approval, active children, or prerequisite completion requirements were not met.', 'at' => now()->toIso8601String()];
                    $project->update(['state' => $state, 'version' => $project->version + 1]);
                });
            }
            self::queueLocal($project->fresh(), false);
            $pending = $project->githubIssues()->whereNotNull('pending_state')->get()->keyBy('node_id');
            $state = $project->fresh()->state;
            $ordered = [];
            $visited = [];
            $visit = function (string $nodeId) use (&$visit, &$ordered, &$visited, $pending, $state): void {
                if (isset($visited[$nodeId])) {
                    return;
                }
                $visited[$nodeId] = true;
                $node = $state['nodes'][$nodeId];
                $children = array_keys(array_filter($state['nodes'], fn (array $child): bool => $child['parent_id'] === $nodeId && $child['status'] !== 'rejected'));
                foreach ([...$children, ...$node['dependencies']] as $required) {
                    if (isset($pending[$required]) && $pending[$required]->pending_state === 'closed') {
                        $visit($required);
                    }
                }
                if (isset($pending[$nodeId]) && $pending[$nodeId]->pending_state === 'closed') {
                    $ordered[] = $pending[$nodeId];
                }
            };
            foreach ($pending as $issue) {
                $visit($issue->node_id);
            }
            $ordered = [...$pending->filter(fn (GitHubIssue $issue): bool => $issue->pending_state === 'open')->values()->all(), ...$ordered];
            foreach ($ordered as $issue) {
                $issue->refresh();
                if ($issue->pending_state === null) {
                    continue;
                }
                $connection = GitHubConnection::findOrFail($issue->github_connection_id);
                $desired = $issue->pending_state;
                try {
                    $this->github->request($connection, 'PATCH', '/repos/'.$project->repository_full_name.'/issues/'.$issue->number, ['state' => $desired], $project->repository_id);
                    DB::transaction(function () use ($issue, $desired): void {
                        $current = GitHubIssue::whereKey($issue->id)->lockForUpdate()->firstOrFail();
                        $current->update(['remote_state' => $desired]);
                    });
                    self::queueLocal($project->fresh(), false);
                    $issue->refresh()->update(['error' => null]);
                } catch (\Throwable $error) {
                    $issue->update(['sync_status' => 'error', 'error' => 'GitHub completion update failed. The desired state is retained for retry.']);
                    throw new RuntimeException('GitHub completion update failed. The desired state is retained for retry.');
                }
            }
        });
    }
}
