<?php

namespace App\Jobs;

use App\Services\GitHubSynchronization;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SynchronizeGitHubIssues implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(public int $projectId) {}

    public function backoff(): array
    {
        return [30, 60, 180, 300];
    }

    public function handle(GitHubSynchronization $synchronization): void
    {
        $synchronization->synchronize($this->projectId);
    }
}
