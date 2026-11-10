<?php

namespace App\Services;

use App\Ai\Agents\DocumentPlanner;
use App\Jobs\AnalyzeProjectDocuments;
use App\Models\AiAnalysis;
use App\Models\AiConnection;
use App\Models\BacklogProject;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Ai;
use Throwable;

class AiPlanning
{
    /** @param array<string, mixed> $input */
    public function start(BacklogProject $project, User $user, array $input): AiAnalysis
    {
        return DB::transaction(function () use ($project, $user, $input): AiAnalysis {
            Team::whereKey($project->team_id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('edit', $project->team);
            $project = BacklogProject::whereKey($project->id)->lockForUpdate()->firstOrFail();
            abort_if($project->version !== $input['version'], 409, 'The project changed. Refresh before starting analysis.');
            $connection = AiConnection::where('team_id', $project->team_id)->findOrFail($input['connection_id']);
            $this->ensure((bool) $connection->api_key, 'Ask an administrator to reconnect this AI provider.');
            $this->ensure(! AiAnalysis::where('backlog_project_id', $project->id)->whereIn('status', ['queued', 'processing'])->exists(), 'An analysis is already in progress for this project.');
            $parent = $input['parent_node_id'] ?? null;
            $this->checkParent($project->state, $parent);
            if ($parent !== null) {
                $attached = $project->state['nodes'][$parent]['document_ids'] ?? [];
                $this->ensure(array_diff($input['document_ids'], $attached) === [], 'Attach these document versions to the selected goal before using them for AI planning.');
            }
            $documents = array_values(array_filter($project->state['documents'], fn (array $document): bool => in_array($document['id'], $input['document_ids'], true)));
            $this->ensure(count($documents) === count($input['document_ids']), 'Select documents belonging to this project.');
            $this->ensure(array_sum(array_map(fn (array $document): int => strlen($document['content']), $documents)) <= 60000, 'Select at most 60 KB of document text per analysis. Split larger documents first.');
            $run = AiAnalysis::create([
                'backlog_project_id' => $project->id, 'user_id' => $user->id, 'ai_connection_id' => $connection->id,
                'connection_revision' => $connection->revision, 'provider' => $connection->provider, 'model' => $connection->model,
                'generation' => $project->generation, 'project_version' => $project->version, 'parent_node_id' => $parent,
                'input' => ['instructions' => DocumentPlanner::make()->instructions(), 'schema_version' => 2, 'project' => $parent ? ['name' => $project->name] : ['name' => $project->name, 'description' => $project->description], 'parent' => $parent ? $this->compactGoal($project->state['nodes'][$parent]) : null, 'direction' => $parent && $project->state['nodes'][$parent]['parent_id'] !== null ? $this->parentDirection($project->state['nodes'][$project->state['nodes'][$parent]['parent_id']]) : null, 'documents' => $documents],
            ]);
            AnalyzeProjectDocuments::dispatch($run->id)->afterCommit();

            return $run;
        });
    }

    /** @param array<string, mixed> $state */
    private function checkParent(array $state, ?string $id): void
    {
        if ($id === null) {
            return;
        }
        $parent = $state['nodes'][$id] ?? null;
        $this->ensure($parent !== null && $parent['type'] === 'goal' && $parent['status'] === 'approved' && $parent['breakdown'] === 'none', 'Select an approved goal that has no smaller steps yet.');
        $this->ensure(! array_filter($state['nodes'], fn (array $node): bool => $node['parent_id'] === $id), 'This goal already has smaller steps.');
        $this->ensure(trim($parent['supports_parent'] ?? '') !== '', 'Write and approve a short direction brief for this goal before requesting smaller steps.');
        if ($parent['parent_id'] !== null) {
            $this->ensure(trim($state['nodes'][$parent['parent_id']]['supports_parent'] ?? '') !== '', 'Add and approve the immediate parent’s short direction brief first.');
        }
        $cursor = $id;
        while ($cursor !== null) {
            foreach ($state['decisions'] as $decision) {
                $this->ensure($decision['node_id'] !== $cursor || $decision['answer'] !== null, 'Answer outstanding questions for this goal first.');
            }
            $ancestor = $state['nodes'][$cursor];
            $this->ensure($ancestor['status'] === 'approved' && ($cursor === $id || $ancestor['breakdown'] === 'approved'), 'Approve the larger goals and their smaller steps first.');
            $cursor = $ancestor['parent_id'];
        }
    }

    /** @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function compactGoal(array $node): array
    {
        return ['id' => $node['id'], 'title' => $node['title'], 'description' => $node['description'], 'supports_parent' => mb_substr($node['supports_parent'] ?? '', 0, 1000), 'revision' => $node['revision']];
    }

    /** @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function parentDirection(array $node): array
    {
        return ['id' => $node['id'], 'title' => $node['title'], 'supports_parent' => mb_substr($node['supports_parent'] ?? '', 0, 1000), 'revision' => $node['revision']];
    }

    public function analyze(int $id): void
    {
        $claimed = AiAnalysis::whereKey($id)->where('status', 'queued')->update(['status' => 'processing', 'started_at' => now()]);
        if (! $claimed) {
            return;
        }
        $run = AiAnalysis::find($id);
        if (! $run) {
            return;
        }
        $stage = 'preflight';
        try {
            $project = $run->project;
            $user = User::findOrFail($run->user_id);
            Gate::forUser($user)->authorize('edit', $project->team);
            $this->ensure($project->generation === $run->generation && $project->version === $run->project_version, 'The project changed before analysis started. Start again with the current documents.');
            $connection = AiConnection::where('team_id', $project->team_id)->findOrFail($run->ai_connection_id);
            $this->ensure($connection->revision === $run->connection_revision && (bool) $connection->api_key, 'The AI connection changed. Start a new analysis.');
            $provider = Ai::build(['name' => 'tracework-'.$connection->id.'-'.$connection->revision, 'driver' => $run->provider, 'key' => $connection->api_key]);
            $input = $run->input;
            unset($input['instructions']);
            foreach ($input['documents'] as &$document) {
                $document['lines'] = array_map(fn (string $line, int $index): array => ['line' => $index + 1, 'text' => $line], explode("\n", $document['content']), array_keys(explode("\n", $document['content'])));
                unset($document['content']);
            }
            unset($document);
            $stage = 'request';
            $response = DocumentPlanner::make($run->input['instructions'])->prompt(json_encode($input, JSON_THROW_ON_ERROR), provider: $provider->name(), model: $run->model, timeout: 45);
            $result = $this->validateResult($run, $response->toArray());
            $run->update(['status' => 'ready', 'result' => $result, 'usage' => $response->usage->toArray(), 'finished_at' => now()]);
        } catch (Throwable $error) {
            $message = $stage === 'preflight' && $error instanceof ValidationException ? collect($error->errors())->flatten()->implode(' ') : ($error instanceof ValidationException ? 'The response could not be verified against your documents. No proposals were added. Try again or refine the documents.' : 'Analysis could not finish. Check the provider key, model access, and usage allowance, then start a new analysis. No proposals were added.');
            $run->update(['status' => 'failed', 'error' => $message, 'finished_at' => now()]);
        }
    }

    /** @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function validateResult(AiAnalysis $run, array $result): array
    {
        Validator::make($result, [
            'summary' => ['required', 'string', 'max:5000'], 'nodes' => ['present', 'array', 'max:8'],
            'nodes.*.key' => ['required', 'string', 'regex:/^[a-zA-Z0-9_-]{1,50}$/', 'distinct'],
            'nodes.*.type' => ['required', 'in:goal,task'], 'nodes.*.title' => ['required', 'string', 'max:180'],
            'nodes.*.supports_parent' => ['required', 'string', 'max:1000'],
            'nodes.*.description' => ['required', 'string', 'max:10000'], 'nodes.*.rationale' => ['required', 'string', 'max:3000'],
            'nodes.*.criteria' => ['present', 'array', 'max:15'], 'nodes.*.criteria.*' => ['required', 'string', 'max:1000'],
            'nodes.*.assumptions' => ['present', 'array', 'max:15'], 'nodes.*.assumptions.*' => ['required', 'string', 'max:1000'],
            'nodes.*.dependencies' => ['present', 'array', 'max:8'], 'nodes.*.dependencies.*' => ['required', 'string'],
            'nodes.*.evidence' => ['required', 'array', 'min:1', 'max:10'],
            'nodes.*.evidence.*.document_id' => ['required', 'string'], 'nodes.*.evidence.*.line' => ['required', 'integer', 'min:1'],
            'nodes.*.evidence.*.end_line' => ['required', 'integer', 'min:1'], 'nodes.*.evidence.*.quote' => ['required', 'string', 'max:5000'],
            'questions' => ['present', 'array', 'max:8'], 'questions.*.node_key' => ['required', 'string', 'distinct'],
            'questions.*.question' => ['required', 'string', 'max:2000'], 'questions.*.proposal' => ['required', 'string', 'max:2000'],
            'questions.*.rationale' => ['required', 'string', 'max:2000'], 'questions.*.alternatives' => ['present', 'array', 'max:5'],
            'questions.*.alternatives.*' => ['required', 'string', 'max:1000'],
        ])->validate();
        $this->ensure($run->parent_node_id !== null || count($result['nodes']) === 0 || (count($result['nodes']) === 1 && $result['nodes'][0]['type'] === 'goal'), 'First suggest exactly one main goal.');
        $nodes = array_column($result['nodes'], null, 'key');
        $documents = array_column($run->input['documents'], null, 'id');
        foreach ($nodes as $key => $node) {
            $this->ensure($node['type'] !== 'task' || count($node['criteria']) > 0, 'Tasks need a completion checklist.');
            $this->ensure($node['type'] === 'task' || $node['dependencies'] === [], 'Only tasks may depend on other tasks.');
            foreach ($node['evidence'] as $source) {
                $document = $documents[$source['document_id']] ?? null;
                $this->ensure($document !== null, 'Evidence references an unselected document.');
                $lines = explode("\n", $document['content']);
                $this->ensure($source['line'] <= $source['end_line'] && $source['end_line'] <= count($lines), 'Evidence line range is invalid.');
                $passage = implode("\n", array_slice($lines, $source['line'] - 1, $source['end_line'] - $source['line'] + 1));
                $this->ensure(trim($source['quote']) !== '' && str_contains($passage, $source['quote']), 'Evidence quote does not match the saved source.');
            }
            $this->ensure(count($node['dependencies']) === count(array_unique($node['dependencies'])), 'Dependencies cannot be repeated.');
            foreach ($node['dependencies'] as $dependency) {
                $this->ensure($dependency !== $key && isset($nodes[$dependency]) && $nodes[$dependency]['type'] === 'task', 'Task dependency is invalid.');
            }
        }
        $visited = [];
        $active = [];
        $visit = function (string $key) use (&$visit, &$visited, &$active, $nodes): void {
            $this->ensure(! isset($active[$key]), 'Dependencies cannot contain a cycle.');
            if (isset($visited[$key])) {
                return;
            }
            $active[$key] = true;
            foreach ($nodes[$key]['dependencies'] as $dependency) {
                $visit($dependency);
            }
            unset($active[$key]);
            $visited[$key] = true;
        };
        foreach (array_keys($nodes) as $key) {
            $visit((string) $key);
        }
        foreach ($result['questions'] as $question) {
            $this->ensure(isset($nodes[$question['node_key']]), 'Question must reference a proposed step.');
        }

        return $result;
    }

    public function apply(AiAnalysis $analysis, User $user): BacklogProject
    {
        return DB::transaction(function () use ($analysis, $user): BacklogProject {
            Team::whereKey($analysis->project->team_id)->lockForUpdate()->firstOrFail();
            $run = AiAnalysis::whereKey($analysis->id)->lockForUpdate()->firstOrFail();
            $project = BacklogProject::whereKey($run->backlog_project_id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('edit', $project->team);
            if ($run->status === 'applied') {
                return $project;
            }
            $this->ensure($run->status === 'ready' && count($run->result['nodes']) > 0, 'Only a verified response with proposals can be added.');
            abort_if($project->generation !== $run->generation || $project->version !== $run->project_version, 409, 'The project changed since analysis began. Start a new analysis to use the current plan and sources.');
            $state = $project->state;
            $this->checkParent($state, $run->parent_node_id);
            $this->ensure(($run->input['schema_version'] ?? 1) >= 2, 'This analysis predates short planning briefs. Start a new analysis before adding drafts.');
            $result = $this->validateResult($run, $run->result);
            $ids = [];
            foreach ($result['nodes'] as $node) {
                $ids[$node['key']] = (string) Str::uuid();
            }
            foreach ($result['nodes'] as $node) {
                $id = $ids[$node['key']];
                $state['nodes'][$id] = [
                    'id' => $id, 'parent_id' => $run->parent_node_id, 'type' => $node['type'], 'template_key' => 'ai_generated', 'goal_id' => null,
                    'title' => $node['title'], 'description' => $node['description'], 'supports_parent' => $node['supports_parent'], 'status' => 'proposed', 'breakdown' => 'none',
                    'revision' => 1, 'published_revision' => null, 'completed_revision' => null, 'completed_at' => null, 'completed_by' => null,
                    'criteria' => $node['criteria'], 'assumptions' => $node['assumptions'], 'dependencies' => array_map(fn (string $key): string => $ids[$key], $node['dependencies']),
                    'document_ids' => $run->parent_node_id === null ? array_column($run->input['documents'], 'id') : [],
                    'evidence' => $node['evidence'], 'rationale' => $node['rationale'], 'ai_analysis_id' => $run->id,
                    'ai_provider' => $run->provider, 'ai_model' => $run->model,
                ];
                $state['history'][] = ['action' => 'ai_proposal_added', 'node_id' => $id, 'revision' => 1, 'actor' => $user->name, 'actor_id' => $user->id, 'analysis_id' => $run->id, 'at' => now()->toIso8601String()];
            }
            foreach ($result['questions'] as $question) {
                $id = $ids[$question['node_key']].'-policy';
                $state['decisions'][$id] = ['id' => $id, 'node_id' => $ids[$question['node_key']], 'question' => $question['question'], 'proposal' => $question['proposal'], 'rationale' => $question['rationale'], 'alternatives' => $question['alternatives'], 'answer' => null, 'resolved_by' => null, 'resolved_at' => null];
            }
            if ($run->parent_node_id) {
                $state['nodes'][$run->parent_node_id]['breakdown'] = 'proposed';
            }
            $state['mode'] = 'ai-assisted';
            $project->update(['state' => $state, 'version' => $project->version + 1]);
            $run->update(['status' => 'applied', 'applied_at' => now()]);

            return $project->load('githubIssues');
        });
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['analysis' => $message]);
        }
    }
}
