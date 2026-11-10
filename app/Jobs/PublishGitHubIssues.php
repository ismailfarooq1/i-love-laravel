<?php

namespace App\Jobs;

use App\Models\GitHubPublication;
use App\Services\GitHubPublishing;
use App\Services\GitHubSynchronization;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class PublishGitHubIssues implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(public int $publicationId) {}

    public function backoff(): array
    {
        return [30, 60, 180, 300];
    }

    public function handle(GitHubPublishing $publishing): void
    {
        $publishing->publish($this->publicationId);
        GitHubSynchronization::queueLocal(GitHubPublication::findOrFail($this->publicationId)->project->fresh());
    }

    public function failed(?Throwable $exception): void
    {
        GitHubPublication::whereKey($this->publicationId)->where('status', '!=', 'published')->update(['status' => 'failed', 'error' => 'Publication stopped. Saved mappings are retained. Review the batch and retry reconciliation.']);
    }
}
