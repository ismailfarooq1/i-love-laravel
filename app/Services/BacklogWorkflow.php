<?php

namespace App\Services;

use App\Models\BacklogProject;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BacklogWorkflow
{
    private ?User $actor = null;

    public function __construct(private DemoScenario $scenario) {}

    public function reset(Team $team, User $actor, ?int $version, bool $example = true, ?BacklogProject $target = null): BacklogProject
    {
        return DB::transaction(function () use ($team, $actor, $version, $example, $target): BacklogProject {
            Team::whereKey($team->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('view', $team);
            Gate::forUser($actor)->authorize('reset', $team);
            $this->actor = $actor;
            $project = BacklogProject::where('team_id', $team->id)->when($target, fn ($query) => $query->whereKey($target->id))->orderBy('id')->lockForUpdate()->first();
            $this->ensure(! $target || $project !== null, 'This project does not belong to the selected team.');
            $this->ensure(! $project || (! $project->githubIssues()->where(fn ($query) => $query->whereNotNull('github_id')->orWhere('creation_status', 'creating'))->exists() && ! $project->publications()->whereIn('status', ['queued', 'publishing'])->exists()), 'Projects with published issues or active publishing cannot be reset. Create a new project instead.');
            abort_if($project && $project->version !== $version, 409, 'The workspace changed. Reload before resetting.');
            $state = $this->scenario->initial();
            if ($example) {
                foreach (array_keys($this->scenario->templates()) as $id) {
                    if (str_starts_with($id, 'custom_')) {
                        continue;
                    }
                    $parent = null;
                    foreach ($this->scenario->templates() as $candidate => $template) {
                        if (in_array($id, $template['children'] ?? [], true)) {
                            $parent = $candidate;
                        }
                    }
                    $state['nodes'][$id] = $this->makeNode($id, $parent, $state);
                }
                foreach (['root', 'requests', 'resolution', 'identity', 'login', 'queue'] as $id) {
                    $state['nodes'][$id]['status'] = 'approved';
                    $this->record($state, 'approved', $id, 1, 'Example reviewer');
                }
                foreach (['root', 'identity', 'resolution'] as $id) {
                    $state['nodes'][$id]['breakdown'] = 'approved';
                }
                $state['nodes']['requests']['breakdown'] = 'proposed';
                $state['nodes']['intake']['breakdown'] = 'proposed';
                $state['nodes']['attachments']['dependencies'] = ['form'];
                $state['nodes']['ownership']['dependencies'] = ['login'];
                $state['nodes']['reply']['dependencies'] = ['queue'];
            }
            if (! $project) {
                return BacklogProject::create(['workspace' => 'team-'.$team->id, 'team_id' => $team->id, 'version' => 1, 'state' => $state, 'name' => $state['name']]);
            }
            $project->update(['state' => $state, 'generation' => (string) Str::uuid(), 'version' => $project->version + 1]);

            return $project->refresh();
        });
    }

    /** @param array<string, mixed> $input */
    public function command(Team $team, User $actor, array $input, ?BacklogProject $target = null): BacklogProject
    {
        return DB::transaction(function () use ($team, $actor, $input, $target): BacklogProject {
            Team::whereKey($team->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('view', $team);
            $ability = match ($input['action']) {
                'approve', 'approve_breakdown', 'reject', 'resolve' => 'review',
                'prepare_batch', 'publish' => 'publish',
                default => 'edit',
            };
            Gate::forUser($actor)->authorize($ability, $team);
            $this->actor = $actor;
            $project = BacklogProject::where('team_id', $team->id)->when($target, fn ($query) => $query->whereKey($target->id))->orderBy('id')->lockForUpdate()->firstOrFail();
            abort_if($project->version !== (int) $input['version'], 409, 'The workspace changed in another tab. Reload and review the latest version.');
            $state = $project->state;
            $id = $input['node_id'] ?? null;
            if ($id !== null) {
                $this->ensure(isset($state['nodes'][$id]), 'The selected node does not exist.');
            }
            switch ($input['action']) {
                case 'complete':
                    $node = $state['nodes'][$id];
                    $this->ensure($this->publishable($state, $id), 'Approve this node and its ancestor breakdowns, and resolve open decisions before completing it.');
                    $children = array_values(array_filter($this->children($state, $id), fn (string $child): bool => $state['nodes'][$child]['status'] !== 'rejected'));
                    $this->ensure($node['type'] !== 'goal' || ($children !== [] && $node['breakdown'] === 'approved'), 'A goal needs an approved child breakdown before it can be completed.');
                    foreach ($children as $child) {
                        $this->ensure($this->isCompleted($state['nodes'][$child]), 'Complete all active child nodes before completing their parent.');
                    }
                    foreach ($node['dependencies'] as $dependency) {
                        $this->ensure($this->isCompleted($state['nodes'][$dependency]), 'Complete all prerequisite tasks before completing this task.');
                    }
                    if (! $this->isCompleted($node)) {
                        $state['nodes'][$id]['completed_revision'] = $node['revision'];
                        $state['nodes'][$id]['completed_at'] = now()->toIso8601String();
                        $state['nodes'][$id]['completed_by'] = $actor->name;
                        $this->record($state, 'completed', $id, $node['revision']);
                    }
                    break;
                case 'reopen':
                    $this->ensure($this->isCompleted($state['nodes'][$id]), 'Only a completed node can be reopened.');
                    $this->reopenAffected($state, $id);
                    break;
                case 'add_goal':
                    $goalId = (string) Str::uuid();
                    $state['nodes'][$goalId] = $this->makeNode($goalId, null, $state, 'custom_root', $goalId);
                    $state['nodes'][$goalId]['title'] = $input['title'];
                    $state['nodes'][$goalId]['description'] = $input['description'];
                    $state['nodes'][$goalId]['assumptions'] = ['This goal was entered by a human. No source-document analysis has been performed.'];
                    $this->record($state, 'goal_added', $goalId, 1);
                    break;
                case 'propose_root':
                    $this->ensure(! isset($state['nodes']['root']), 'The support portal example goal already exists. Add a new goal instead.');
                    $state['nodes']['root'] = $this->makeNode('root', null, $state);
                    break;
                case 'expand':
                    $this->ensure($state['nodes'][$id]['type'] === 'goal', 'Only goals can be decomposed.');
                    $this->ensure($state['nodes'][$id]['status'] === 'approved' && $this->ancestorsApproved($state, $id), 'Approve this goal and its ancestors before requesting a breakdown.');
                    $this->ensure($state['nodes'][$id]['breakdown'] === 'none', 'Review the existing breakdown before continuing.');
                    $this->ensure($this->blockers($state, $id) === [], 'Resolve this branch’s open decisions before continuing.');
                    foreach ($this->scenario->templates()[$state['nodes'][$id]['template_key'] ?? $id]['children'] as $child) {
                        $goalId = $state['nodes'][$id]['goal_id'] ?? null;
                        $childId = $goalId !== null ? $goalId.':'.$child : $child;
                        $state['nodes'][$childId] = $this->makeNode($childId, $id, $state, $child, $goalId);
                    }
                    $state['nodes'][$id]['breakdown'] = 'proposed';
                    break;
                case 'approve':
                    $this->ensure($this->blockers($state, $id) === [], 'Resolve this branch’s open decisions before approving.');
                    $this->ensure($state['nodes'][$id]['status'] !== 'rejected', 'Edit a rejected proposal before approving it.');
                    $this->ensure($state['nodes'][$id]['type'] !== 'task' || count($state['nodes'][$id]['criteria']) > 0, 'A task needs acceptance criteria.');
                    $this->ensure($this->ancestorsApproved($state, $id), 'Approve the ancestor goals and breakdowns first.');
                    $state['nodes'][$id]['status'] = 'approved';
                    $this->record($state, 'approved', $id, $state['nodes'][$id]['revision']);
                    break;
                case 'approve_breakdown':
                    $this->ensure($state['nodes'][$id]['status'] === 'approved' && $this->ancestorsApproved($state, $id), 'Approve the parent and its ancestors first.');
                    $children = $this->children($state, $id);
                    $this->ensure($children !== [], 'Generate a breakdown first.');
                    foreach ($children as $child) {
                        if ($state['nodes'][$child]['status'] === 'rejected') {
                            continue;
                        }
                        $this->ensure($this->blockers($state, $child) === [], 'Resolve open decisions in the proposed children first.');
                        $this->ensure($state['nodes'][$child]['type'] !== 'task' || count($state['nodes'][$child]['criteria']) > 0, 'Each task needs acceptance criteria.');
                        $state['nodes'][$child]['status'] = 'approved';
                        $this->record($state, 'approved', $child, $state['nodes'][$child]['revision']);
                    }
                    $this->ensure(count(array_filter($children, fn (string $child): bool => $state['nodes'][$child]['status'] !== 'rejected')) > 0, 'A breakdown needs at least one accepted child.');
                    $state['nodes'][$id]['breakdown'] = 'approved';
                    $this->record($state, 'breakdown_approved', $id, $state['nodes'][$id]['revision']);
                    break;
                case 'reject':
                    $this->invalidate($state, $id);
                    $state['nodes'][$id]['status'] = 'rejected';
                    $this->record($state, 'rejected', $id, $state['nodes'][$id]['revision']);
                    break;
                case 'edit':
                    $node = $state['nodes'][$id];
                    $dependencies = $input['dependencies'] ?? $node['dependencies'];
                    foreach ($dependencies as $dependency) {
                        $this->ensure(isset($state['nodes'][$dependency]) && $state['nodes'][$dependency]['type'] === 'task' && $node['type'] === 'task', 'Dependencies must reference existing tasks.');
                        $this->ensure($dependency !== $id && ! $this->dependsOn($state, $dependency, $id), 'Task dependencies cannot contain a cycle.');
                    }
                    $this->record($state, 'revision_superseded', $id, $node['revision']);
                    $this->invalidate($state, $id);
                    $state['nodes'][$id] = array_replace($state['nodes'][$id], [
                        'title' => $input['title'], 'description' => $input['description'],
                        'criteria' => $input['criteria'] ?? $node['criteria'], 'dependencies' => $dependencies,
                    ]);
                    $this->record($state, 'edited', $id, $state['nodes'][$id]['revision']);
                    break;
                case 'resolve':
                    $decisionId = $id.'-policy';
                    $this->ensure(isset($state['decisions'][$decisionId]), 'This node has no clarification to resolve.');
                    $this->invalidate($state, $id);
                    $state['decisions'][$decisionId]['answer'] = $input['answer'];
                    $state['decisions'][$decisionId]['resolved_by'] = $actor->name;
                    $state['decisions'][$decisionId]['resolved_at'] = now()->toIso8601String();
                    $this->record($state, 'decision_resolved', $id, $state['nodes'][$id]['revision']);
                    break;
                case 'document':
                    $this->addDocument($state, $input);
                    break;
                case 'prepare_batch':
                    $this->ensure(! $project->repository_id, 'Use the real GitHub publication preview for connected projects.');
                    $selected = $input['node_ids'];
                    foreach ($input['node_ids'] as $selectedId) {
                        $this->ensure(isset($state['nodes'][$selectedId]), 'A selected node does not exist.');
                        $parent = $state['nodes'][$selectedId]['parent_id'];
                        while ($parent !== null) {
                            $selected[] = $parent;
                            $parent = $state['nodes'][$parent]['parent_id'];
                        }
                    }
                    $selected = array_values(array_unique($selected));
                    $items = [];
                    foreach ($selected as $selectedId) {
                        $this->ensure($this->publishable($state, $selectedId), 'All selected nodes and their ancestors must have current approvals and resolved decisions.');
                        $node = $state['nodes'][$selectedId];
                        $lineage = [];
                        $cursor = $selectedId;
                        do {
                            $lineage[] = $cursor;
                            $cursor = $state['nodes'][$cursor]['parent_id'];
                        } while ($cursor !== null);
                        $items[] = ['node_id' => $selectedId, 'revision' => $node['revision'], 'title' => $node['title'], 'parent_id' => $node['parent_id'], 'description' => $node['description'], 'criteria' => $node['criteria'], 'dependencies' => $node['dependencies'], 'evidence' => $node['evidence'], 'assumptions' => $node['assumptions'], 'decisions' => array_values(array_filter($state['decisions'], fn (array $decision): bool => $decision['answer'] !== null && in_array($decision['node_id'], $lineage, true)))];
                    }
                    $batchId = (string) Str::uuid();
                    $state['batches'][] = ['id' => $batchId, 'status' => 'preview', 'items' => $items, 'created_at' => now()->toIso8601String()];
                    $this->record($state, 'publication_prepared', null, null);
                    break;
                case 'publish':
                    $this->ensure(! $project->repository_id, 'Use the real GitHub publication approval for connected projects.');
                    $index = array_search($input['batch_id'], array_column($state['batches'], 'id'), true);
                    $this->ensure($index !== false, 'The publication batch does not exist.');
                    if ($state['batches'][$index]['status'] === 'published') {
                        break;
                    }
                    $this->ensure($state['batches'][$index]['status'] === 'preview', 'This preview is stale. Prepare a new batch.');
                    foreach ($state['batches'][$index]['items'] as $item) {
                        $this->ensure($this->publishable($state, $item['node_id']) && $state['nodes'][$item['node_id']]['revision'] === $item['revision'], 'The batch changed. Prepare a new preview and review it.');
                    }
                    foreach ($state['batches'][$index]['items'] as $item) {
                        $state['nodes'][$item['node_id']]['published_revision'] = $item['revision'];
                    }
                    $state['batches'][$index]['status'] = 'published';
                    $state['batches'][$index]['approved_by'] = $actor->name;
                    $state['batches'][$index]['approved_at'] = now()->toIso8601String();
                    $this->record($state, 'simulation_published', null, null);
                    break;
            }
            $project->update(['state' => $state, 'version' => $project->version + 1]);
            GitHubSynchronization::queueLocal($project->refresh());

            return $project->refresh();
        });
    }

    /** @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function makeNode(string $id, ?string $parent, array &$state, ?string $templateKey = null, ?string $goalId = null): array
    {
        $templateKey ??= $id;
        $template = $this->scenario->templates()[$templateKey];
        $document = $state['documents'][0];
        $scriptedSource = isset($template['line']) && $document['content'] === DemoScenario::DOCUMENT;
        $evidence = $scriptedSource ? [['document_id' => $document['id'], 'line' => $template['line'], 'quote' => explode("\n", $document['content'])[$template['line'] - 1]]] : [];
        if ($template['question'] ?? false) {
            $state['decisions'][$id.'-policy'] = [
                'id' => $id.'-policy', 'node_id' => $id,
                'question' => 'Which file formats and size limit should attachments allow?',
                'proposal' => 'Allow PDF, PNG, and JPEG files up to 10 MB each.',
                'rationale' => 'A small initial policy limits storage and validation complexity. These limits are proposed, not specified in the brief.',
                'alternatives' => ['Text-only requests in the first release.', 'Allow more formats with scanning and a larger limit.'],
                'answer' => null, 'resolved_by' => null, 'resolved_at' => null,
            ];
        }

        return [
            'id' => $id, 'template_key' => $templateKey, 'goal_id' => $goalId, 'parent_id' => $parent, 'type' => isset($template['children']) ? 'goal' : 'task',
            'title' => $template['title'], 'description' => $template['description'],
            'status' => 'proposed', 'breakdown' => 'none', 'revision' => 1, 'published_revision' => null,
            'completed_revision' => null, 'completed_at' => null, 'completed_by' => null,
            'criteria' => $template['criteria'] ?? [], 'dependencies' => array_map(fn (string $dependency): string => $goalId !== null ? $goalId.':'.$dependency : $dependency, $template['dependencies'] ?? []), 'evidence' => $evidence,
            'assumptions' => [$template['assumption'] ?? 'This breakdown is a scripted proposal. Review whether it fits your intended scope.', ...($scriptedSource ? [] : ['This generic scripted proposal has no source evidence. Adapt it to the goal before approving.'])],
        ];
    }

    /** @param array<string, mixed> $state
     * @return list<string>
     */
    private function children(array $state, string $id): array
    {
        return array_keys(array_filter($state['nodes'], fn (array $node): bool => $node['parent_id'] === $id));
    }

    /** @param array<string, mixed> $state
     * @return list<string>
     */
    private function blockers(array $state, string $id): array
    {
        $ids = [];
        do {
            $ids[] = $id;
            $id = $state['nodes'][$id]['parent_id'];
        } while ($id !== null);

        return array_keys(array_filter($state['decisions'], fn (array $decision): bool => in_array($decision['node_id'], $ids, true) && $decision['answer'] === null));
    }

    /** @param array<string, mixed> $state */
    private function ancestorsApproved(array $state, string $id): bool
    {
        $parent = $state['nodes'][$id]['parent_id'];
        while ($parent !== null) {
            if ($state['nodes'][$parent]['status'] !== 'approved' || $state['nodes'][$parent]['breakdown'] !== 'approved') {
                return false;
            }
            $parent = $state['nodes'][$parent]['parent_id'];
        }

        return true;
    }

    /** @param array<string, mixed> $state */
    public function publishable(array $state, string $id): bool
    {
        $node = $state['nodes'][$id];

        return $node['status'] === 'approved' && $this->ancestorsApproved($state, $id) && $this->blockers($state, $id) === []
            && in_array($node['breakdown'], ['none', 'approved'], true);
    }

    /** @param array<string, mixed> $state */
    private function invalidate(array &$state, string $id, string $reason = 'This proposal or a human decision changed. Compare the recorded revisions before approving.', bool $preserveRejection = false): void
    {
        $this->reopenAffected($state, $id);
        $state['nodes'][$id]['status'] = $preserveRejection && $state['nodes'][$id]['status'] === 'rejected' ? 'rejected' : 'needs_review';
        $state['nodes'][$id]['review_reason'] = $reason;
        $state['nodes'][$id]['revision']++;
        if ($this->children($state, $id) !== []) {
            $state['nodes'][$id]['breakdown'] = 'proposed';
        }
        foreach ($this->children($state, $id) as $child) {
            $this->invalidate($state, $child, 'An ancestor changed. Review this proposal against the updated goal and source evidence.', true);
        }
        foreach ($state['batches'] as &$batch) {
            if ($batch['status'] === 'preview' && in_array($id, array_column($batch['items'], 'node_id'), true)) {
                $batch['status'] = 'stale';
            }
        }
    }

    /** @param array<string, mixed> $node */
    private function isCompleted(array $node): bool
    {
        return $node['status'] === 'approved' && ($node['completed_revision'] ?? null) === $node['revision'];
    }

    /** @param array<string, mixed> $state */
    private function reopenAffected(array &$state, string $id): void
    {
        $pending = [$id];
        $visited = [];
        while ($pending !== []) {
            $current = array_pop($pending);
            if (in_array($current, $visited, true)) {
                continue;
            }
            $visited[] = $current;
            if (($state['nodes'][$current]['completed_revision'] ?? null) !== null) {
                $state['nodes'][$current]['completed_revision'] = null;
                $state['nodes'][$current]['completed_at'] = null;
                $state['nodes'][$current]['completed_by'] = null;
                $this->record($state, 'completion_reopened', $current, $state['nodes'][$current]['revision']);
            }
            $parent = $state['nodes'][$current]['parent_id'];
            if ($parent !== null) {
                $pending[] = $parent;
            }
            foreach ($state['nodes'] as $candidate) {
                if (in_array($current, $candidate['dependencies'], true)) {
                    $pending[] = $candidate['id'];
                }
            }
        }
    }

    /** @param array<string, mixed> $state */
    private function dependsOn(array $state, string $from, string $target): bool
    {
        foreach ($state['nodes'][$from]['dependencies'] as $dependency) {
            if ($dependency === $target || $this->dependsOn($state, $dependency, $target)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $state
     * @param  array<string, mixed>  $input
     */
    private function addDocument(array &$state, array $input): void
    {
        $previous = null;
        if (! empty($input['document_id'])) {
            foreach ($state['documents'] as $document) {
                if ($document['id'] === $input['document_id']) {
                    $previous = $document;
                }
            }
            $this->ensure($previous !== null, 'The source document does not exist.');
            $this->ensure(! in_array($previous['id'], array_column($state['documents'], 'previous_id'), true), 'Revise the latest document version.');
        }
        $this->ensure(mb_check_encoding($input['content'], 'UTF-8') && ! str_contains($input['content'], "\0"), 'Documents must contain UTF-8 text.');
        $documentId = (string) Str::uuid();
        $state['documents'][] = ['id' => $documentId, 'name' => $input['name'], 'content' => $input['content'], 'version' => ($previous['version'] ?? 0) + 1, 'previous_id' => $previous['id'] ?? null, 'created_at' => now()->toIso8601String()];
        if ($previous !== null) {
            $affected = [];
            foreach ($state['nodes'] as $node) {
                if (in_array($previous['id'], array_column($node['evidence'], 'document_id'), true)) {
                    $affected[] = $node['id'];
                }
            }
            foreach ($affected as $id) {
                if (! in_array($state['nodes'][$id]['parent_id'], $affected, true)) {
                    $this->invalidate($state, $id, 'A source document has a new version. Compare the original evidence with the latest source before approving.');
                }
            }
        }
        $this->record($state, 'document_added', null, null);
    }

    /** @param array<string, mixed> $state */
    private function record(array &$state, string $action, ?string $id, ?int $revision, ?string $actor = null): void
    {
        $state['history'][] = ['action' => $action, 'node_id' => $id, 'revision' => $revision, 'actor' => $actor ?? $this->actor?->name, 'actor_id' => $actor !== null ? null : $this->actor?->id, 'at' => now()->toIso8601String(), 'snapshot' => $id !== null ? $state['nodes'][$id] : null, 'decision' => $id !== null ? ($state['decisions'][$id.'-policy'] ?? null) : null];
    }

    /** @param array<string, mixed> $state */
    public function completionAllowed(array $state, string $id): bool
    {
        if (! isset($state['nodes'][$id]) || ! $this->publishable($state, $id)) {
            return false;
        }
        $node = $state['nodes'][$id];
        $children = array_filter($this->children($state, $id), fn (string $child): bool => $state['nodes'][$child]['status'] !== 'rejected');
        if ($node['type'] === 'goal' && ($children === [] || $node['breakdown'] !== 'approved')) {
            return false;
        }
        foreach ([...$children, ...$node['dependencies']] as $required) {
            if (! $this->isCompleted($state['nodes'][$required])) {
                return false;
            }
        }

        return true;
    }

    public function applyGitHubState(BacklogProject $project, string $nodeId, bool $closed, string $login): bool
    {
        return DB::transaction(function () use ($project, $nodeId, $closed, $login): bool {
            Team::whereKey($project->team_id)->lockForUpdate()->firstOrFail();
            $fresh = BacklogProject::whereKey($project->id)->lockForUpdate()->firstOrFail();
            if ($fresh->generation !== $project->generation || ! isset($fresh->state['nodes'][$nodeId])) {
                return false;
            }
            $state = $fresh->state;
            $this->actor = null;
            $label = 'GitHub: '.$login;
            $complete = $this->isCompleted($state['nodes'][$nodeId]);
            if ($closed && ! $complete) {
                if (! $this->completionAllowed($state, $nodeId)) {
                    return false;
                }
                $state['nodes'][$nodeId]['completed_revision'] = $state['nodes'][$nodeId]['revision'];
                $state['nodes'][$nodeId]['completed_by'] = $label;
                $state['nodes'][$nodeId]['completed_at'] = now()->toIso8601String();
                $this->record($state, 'github_completed', $nodeId, $state['nodes'][$nodeId]['revision'], $label);
            } elseif (! $closed && $complete) {
                $this->reopenAffected($state, $nodeId);
                $this->record($state, 'github_reopened', $nodeId, $state['nodes'][$nodeId]['revision'], $label);
            } else {
                return true;
            }
            $fresh->update(['state' => $state, 'version' => $fresh->version + 1]);

            return true;
        });
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['workflow' => $message]);
        }
    }
}
