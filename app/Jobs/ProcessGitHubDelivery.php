<?php

namespace App\Jobs;

use App\Models\GitHubConnection;
use App\Models\GitHubDelivery;
use App\Models\GitHubIssue;
use App\Services\GitHubSynchronization;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class ProcessGitHubDelivery implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(public int $deliveryId) {}

    public function backoff(): array
    {
        return [30, 60, 180, 300];
    }

    public function handle(GitHubSynchronization $synchronization): void
    {
        $delivery = GitHubDelivery::findOrFail($this->deliveryId);
        if ($delivery->status === 'processed') {
            return;
        }
        try {
            $payload = $delivery->payload;
            if ($delivery->event === 'installation') {
                if (in_array($payload['action'] ?? '', ['deleted', 'suspend'], true)) {
                    GitHubConnection::where('mode', 'app')->where('installation_id', (string) ($payload['installation']['id'] ?? ''))->update(['revoked' => true]);
                }
            } elseif ($delivery->event === 'issues' && in_array($payload['action'] ?? '', ['closed', 'reopened'], true)) {
                $issues = GitHubIssue::where('github_id', (string) ($payload['issue']['id'] ?? ''))->with('project')->get();
                foreach ($issues as $issue) {
                    $project = $issue->project;
                    if (! $project || $project->generation !== $issue->generation || $project->repository_id !== (string) ($payload['repository']['id'] ?? '') || ! $project->github_connection_id) {
                        continue;
                    }
                    $connection = GitHubConnection::findOrFail($issue->github_connection_id);
                    if ($connection->mode === 'app' && (string) $connection->installation_id !== (string) ($payload['installation']['id'] ?? '')) {
                        continue;
                    }
                    $synchronization->synchronize($project->id, $payload['sender']['login'] ?? 'repository user');
                }
            }
            $delivery->update(['status' => 'processed', 'error' => null]);
        } catch (Throwable $error) {
            $delivery->update(['status' => 'failed', 'error' => 'GitHub delivery processing failed. It can be safely retried.']);
            throw new RuntimeException('GitHub delivery processing failed. It can be safely retried.');
        }
    }
}
