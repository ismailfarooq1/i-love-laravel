<?php

namespace App\Services;

use App\Jobs\PublishGitHubIssues;
use App\Models\BacklogProject;
use App\Models\GitHubConnection;
use App\Models\GitHubIssue;
use App\Models\GitHubPublication;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class GitHubPublishing
{
    public function __construct(private GitHubClient $github, private BacklogWorkflow $workflow) {}

    public function connection(BacklogProject $project, User $user): GitHubConnection
    {
        $connection = $project->connection_mode === 'app' ? $project->githubConnection : GitHubConnection::where('team_id', $project->team_id)->where('user_id', $user->id)->where('mode', 'oauth')->first();
        $this->ensure($project->github_connection_id !== null && $project->repository_id !== null && $connection !== null && ! $connection->revoked, 'Connect this project and your publishing identity to GitHub first.');

        return $connection;
    }

    /** @param list<string> $ids */
    public function prepare(BacklogProject $project, User $user, int $version, array $ids): GitHubPublication
    {
        Gate::forUser($user)->authorize('view', $project->team);
        Gate::forUser($user)->authorize('publish', $project->team);
        abort_if($project->version !== $version, 409, 'The project changed. Reload before preparing a publication.');
        $connection = $this->connection($project, $user);
        $repo = $this->github->request($connection, 'GET', '/repositories/'.$project->repository_id, repositoryId: $project->repository_id);
        $this->ensure(($repo['has_issues'] ?? false) && ! ($repo['archived'] ?? false), 'The repository must have issues enabled and must not be archived.');
        if ($connection->mode === 'oauth') {
            $this->ensure($repo['permissions']['push'] ?? false, 'Your GitHub identity needs write access to publish issues.');
        }
        $nodes = $project->state['nodes'];
        for ($i = 0; $i < count($ids); $i++) {
            $id = $ids[$i];
            $this->ensure(isset($nodes[$id]), 'A selected node does not exist in this project.');
            foreach (array_filter([$nodes[$id]['parent_id'], ...$nodes[$id]['dependencies']]) as $required) {
                if (! in_array($required, $ids, true)) {
                    $ids[] = $required;
                }
            }
            $this->ensure(count($ids) <= 1000, 'Publish a smaller branch at a time.');
        }
        $depth = function (string $id) use ($nodes): int {
            $level = 0;
            while ($nodes[$id]['parent_id'] !== null) {
                $level++;
                $id = $nodes[$id]['parent_id'];
            }

            return $level;
        };
        usort($ids, fn (string $a, string $b): int => $depth($a) <=> $depth($b));
        $items = [];
        foreach ($ids as $id) {
            $node = $nodes[$id];
            $this->ensure($this->workflow->publishable($project->state, $id), 'All selected nodes, ancestors, and prerequisites need current approvals and resolved decisions.');
            $this->ensure($depth($id) <= 8, 'GitHub supports at most eight nested sub-issue levels. Reduce the hierarchy before publishing.');
            $this->ensure(count(array_filter($nodes, fn (array $child): bool => $child['parent_id'] === $id && $child['status'] !== 'rejected')) <= 100, 'GitHub allows at most 100 sub-issues per parent.');
            $issue = GitHubIssue::firstOrCreate(['backlog_project_id' => $project->id, 'generation' => $project->generation, 'node_id' => $id], ['github_connection_id' => $connection->id, 'marker' => (string) Str::uuid()]);
            $body = $this->body($project, $node, $issue->marker);
            $this->ensure(strlen($body) <= 60000, 'This issue body is too large. Shorten the task or evidence before publishing.');
            $remote = $issue->github_id ? $this->github->request($connection, 'GET', '/repos/'.$project->repository_full_name.'/issues/'.$issue->number, repositoryId: $project->repository_id) : null;
            $items[] = ['node_id' => $id, 'revision' => $node['revision'], 'parent_id' => $node['parent_id'], 'dependencies' => $node['dependencies'], 'title' => $node['title'], 'body' => $body,
                'operation' => ! $remote ? 'create' : ($remote['title'] === $node['title'] && ($remote['body'] ?? '') === $body ? 'unchanged' : 'update'),
                'remote_hash' => $remote ? $this->contentHash($remote) : null, 'remote_title' => $remote['title'] ?? null, 'remote_body' => $remote['body'] ?? null, 'issue_id' => $issue->id];
        }

        return DB::transaction(function () use ($project, $user, $connection, $items, $version): GitHubPublication {
            Team::whereKey($project->team_id)->lockForUpdate()->firstOrFail();
            $fresh = BacklogProject::whereKey($project->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('publish', $fresh->team);
            abort_if($fresh->version !== $version || $fresh->repository_id !== $project->repository_id || $fresh->github_connection_id !== $project->github_connection_id, 409, 'The project changed while preparing the preview.');

            return GitHubPublication::create(['backlog_project_id' => $project->id, 'user_id' => $user->id, 'github_connection_id' => $connection->id, 'generation' => $project->generation, 'repository_id' => $project->repository_id, 'repository_full_name' => $project->repository_full_name, 'items' => $items, 'status' => 'preview']);
        });
    }

    public function approve(GitHubPublication $publication, User $user): GitHubPublication
    {
        return DB::transaction(function () use ($publication, $user): GitHubPublication {
            $project = $publication->project;
            Team::whereKey($project->team_id)->lockForUpdate()->firstOrFail();
            $publication = GitHubPublication::whereKey($publication->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('view', $project->team);
            Gate::forUser($user)->authorize('publish', $project->team);
            $this->ensure($publication->user_id === $user->id, 'Prepare your own preview before approving publication under your identity.');
            if (in_array($publication->status, ['queued', 'publishing', 'published'], true)) {
                return $publication;
            }
            $this->validate($publication);
            $publication->update(['status' => 'queued', 'approved_at' => now(), 'error' => null]);
            PublishGitHubIssues::dispatch($publication->id)->afterCommit();

            return $publication;
        });
    }

    public function publish(int $id): void
    {
        $publication = GitHubPublication::find($id);
        if (! $publication) {
            return;
        }
        Cache::lock('github-project-'.$publication->backlog_project_id, 180)->block(5, function () use ($publication): void {
            if (! $publication->fresh() || $publication->fresh()->status === 'published') {
                return;
            }
            try {
                $this->ensure($publication->approved_at !== null, 'A human must approve this publication before execution.');
                $this->validate($publication);
                $publication->update(['status' => 'publishing', 'error' => null]);
                $project = $publication->project->fresh();
                $connection = GitHubConnection::findOrFail($publication->github_connection_id);
                foreach ($publication->items as $item) {
                    $this->validate($publication);
                    $issue = GitHubIssue::findOrFail($item['issue_id']);
                    if (! $issue->github_id) {
                        if ($issue->creation_status === 'creating') {
                            $remote = collect($this->github->pages($connection, '/repos/'.$project->repository_full_name.'/issues', $project->repository_id, query: ['state' => 'all']))->filter(fn (array $candidate): bool => ! isset($candidate['pull_request']) && str_contains($candidate['body'] ?? '', '<!-- tracework:'.$issue->marker.' -->'))->values();
                            $this->ensure($remote->count() === 1, 'An earlier issue creation has an uncertain outcome. Reconciliation did not find exactly one matching issue. Inspect GitHub before retrying creation.');
                            $created = $remote->first();
                        } else {
                            $issue->update(['creation_status' => 'creating']);
                            $created = $this->github->request($connection, 'POST', '/repos/'.$project->repository_full_name.'/issues', ['title' => $item['title'], 'body' => $item['body']], $project->repository_id);
                        }
                        $this->ensure(isset($created['id'], $created['number']), 'GitHub did not return an issue identifier. Reconcile before retrying.');
                        $issue->update(['github_id' => (string) $created['id'], 'number' => $created['number'], 'url' => $created['html_url'], 'creation_status' => 'created', 'github_connection_id' => $connection->id, 'remote_state' => $created['state'] ?? 'open']);
                    }
                    if (in_array($item['operation'], ['update', 'unchanged'], true)) {
                        $remote = $this->github->request($connection, 'GET', '/repos/'.$project->repository_full_name.'/issues/'.$issue->number, repositoryId: $project->repository_id);
                        if ($remote['title'] !== $item['title'] || ($remote['body'] ?? '') !== $item['body']) {
                            $this->ensure($this->contentHash($remote) === $item['remote_hash'], 'This GitHub issue changed after preview. Prepare a new preview rather than overwriting another person’s edits.');
                            $this->github->request($connection, 'PATCH', '/repos/'.$project->repository_full_name.'/issues/'.$issue->number, ['title' => $item['title'], 'body' => $item['body']], $project->repository_id);
                        }
                    }
                }
                foreach ($publication->items as $item) {
                    $this->validate($publication);
                    $issue = GitHubIssue::findOrFail($item['issue_id']);
                    if ($item['parent_id']) {
                        $parent = $project->githubIssues()->where('node_id', $item['parent_id'])->firstOrFail();
                        $children = $this->github->pages($connection, '/repos/'.$project->repository_full_name.'/issues/'.$parent->number.'/sub_issues', $project->repository_id);
                        if (! collect($children)->contains(fn (array $child): bool => (string) $child['id'] === $issue->github_id)) {
                            $this->ensure(count($children) < 100, 'The GitHub parent already has 100 sub-issues.');
                            $this->github->request($connection, 'POST', '/repos/'.$project->repository_full_name.'/issues/'.$parent->number.'/sub_issues', ['sub_issue_id' => (int) $issue->github_id, 'replace_parent' => false], $project->repository_id);
                        }
                    }
                    if ($item['dependencies'] !== [] || ($issue->managed_dependencies ?? []) !== []) {
                        $existing = $this->github->pages($connection, '/repos/'.$project->repository_full_name.'/issues/'.$issue->number.'/dependencies/blocked_by', $project->repository_id);
                        foreach (array_diff($issue->managed_dependencies ?? [], $item['dependencies']) as $removed) {
                            $old = $project->githubIssues()->where('node_id', $removed)->first();
                            if ($old && collect($existing)->contains(fn (array $entry): bool => (string) $entry['id'] === $old->github_id)) {
                                $this->github->request($connection, 'DELETE', '/repos/'.$project->repository_full_name.'/issues/'.$issue->number.'/dependencies/blocked_by/'.$old->github_id, repositoryId: $project->repository_id);
                            }
                        }
                        foreach ($item['dependencies'] as $dependency) {
                            $required = $project->githubIssues()->where('node_id', $dependency)->firstOrFail();
                            if (! collect($existing)->contains(fn (array $entry): bool => (string) $entry['id'] === $required->github_id)) {
                                $this->github->request($connection, 'POST', '/repos/'.$project->repository_full_name.'/issues/'.$issue->number.'/dependencies/blocked_by', ['issue_id' => (int) $required->github_id], $project->repository_id);
                            }
                        }
                    }
                    $issue->update(['managed_dependencies' => $item['dependencies'], 'github_connection_id' => $connection->id, 'published_revision' => $item['revision'], 'sync_status' => 'synced', 'error' => null]);
                }
                DB::transaction(function () use ($publication): void {
                    $project = BacklogProject::whereKey($publication->backlog_project_id)->lockForUpdate()->firstOrFail();
                    $state = $project->state;
                    foreach ($publication->items as $item) {
                        if ($project->generation === $publication->generation && ($state['nodes'][$item['node_id']]['revision'] ?? null) === $item['revision']) {
                            $issue = GitHubIssue::findOrFail($item['issue_id']);
                            $state['nodes'][$item['node_id']]['published_revision'] = $item['revision'];
                            $state['nodes'][$item['node_id']]['publication_mode'] = 'live';
                            $state['nodes'][$item['node_id']]['github_url'] = $issue->url;
                        }
                    }
                    $state['history'][] = ['action' => 'github_published', 'node_id' => null, 'revision' => null, 'actor_id' => $publication->user_id, 'actor' => User::find($publication->user_id)?->name, 'at' => now()->toIso8601String()];
                    $project->update(['state' => $state, 'version' => $project->version + 1]);
                    $publication->update(['status' => 'published', 'error' => null]);
                });
            } catch (Throwable $error) {
                $message = $error instanceof ValidationException ? collect($error->errors())->flatten()->implode(' ') : ($error instanceof RuntimeException ? $error->getMessage() : 'GitHub publishing was interrupted. Reconcile and retry; completed issue mappings were preserved.');
                $publication->update(['status' => 'failed', 'error' => $message]);
                throw new RuntimeException($message);
            }
        });
    }

    private function validate(GitHubPublication $publication): void
    {
        $project = $publication->project->fresh();
        $user = User::findOrFail($publication->user_id);
        Gate::forUser($user)->authorize('view', $project->team);
        Gate::forUser($user)->authorize('publish', $project->team);
        $this->ensure($project->generation === $publication->generation && $project->repository_id === $publication->repository_id && $project->github_connection_id !== null, 'This publication no longer matches the connected project. Prepare a new preview.');
        $connection = GitHubConnection::findOrFail($publication->github_connection_id);
        $this->ensure($connection->mode === $project->connection_mode && ($connection->mode !== 'app' || $connection->id === $project->github_connection_id), 'The project connection changed. Prepare a new preview.');
        $this->ensure(! $connection->revoked && $connection->team_id === $project->team_id && ($connection->mode === 'app' || $connection->user_id === $user->id), 'The publishing connection is no longer authorized.');
        foreach ($publication->items as $item) {
            $this->ensure(isset($project->state['nodes'][$item['node_id']]) && $project->state['nodes'][$item['node_id']]['revision'] === $item['revision'] && $this->workflow->publishable($project->state, $item['node_id']), 'A proposal changed or lost approval. Prepare and approve a new preview.');
        }
    }

    /** @param array<string, mixed> $node */
    private function body(BacklogProject $project, array $node, string $marker): string
    {
        $body = $node['description']."\n\n## Acceptance criteria\n";
        foreach ($node['criteria'] as $criterion) {
            $body .= '- [ ] '.$criterion."\n";
        }
        $body .= "\n## Assumptions\n".implode("\n", array_map(fn (string $assumption): string => '- '.$assumption, $node['assumptions']));
        $body .= "\n\n## Source evidence\n";
        foreach ($node['evidence'] as $evidence) {
            $source = collect($project->state['documents'])->firstWhere('id', $evidence['document_id']);
            $body .= ($source['name'] ?? 'Source').' · v'.($source['version'] ?? 1).' · line '.$evidence['line']."\n\n> ".str_replace("\n", "\n> ", $evidence['quote'])."\n\n";
        }
        $lineage = [$node['id']];
        $parent = $node['parent_id'];
        while ($parent) {
            $lineage[] = $parent;
            $parent = $project->state['nodes'][$parent]['parent_id'];
        }
        foreach ($project->state['decisions'] as $decision) {
            if ($decision['answer'] && in_array($decision['node_id'], $lineage, true)) {
                $body .= "\n## Human decision\n".$decision['question']."\n\n".$decision['answer']."\n";
            }
        }

        return $body."\n\n---\nManaged by Tracework. Parent–child links describe scope; prerequisite links describe execution order.\n\n<!-- tracework:".$marker.' -->';
    }

    /** @param array<string, mixed> $remote */
    private function contentHash(array $remote): string
    {
        return hash('sha256', json_encode([$remote['title'], $remote['body'] ?? ''], JSON_THROW_ON_ERROR));
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['publication' => $message]);
        }
    }
}
