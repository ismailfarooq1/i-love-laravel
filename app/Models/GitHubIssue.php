<?php

namespace App\Models;

use Database\Factories\GitHubIssueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GitHubIssue extends Model
{
    /** @use HasFactory<GitHubIssueFactory> */
    use HasFactory;

    protected $fillable = ['backlog_project_id', 'github_connection_id', 'generation', 'node_id', 'marker', 'github_id', 'number', 'url', 'creation_status', 'managed_dependencies', 'published_revision', 'remote_state', 'desired_state', 'pending_state', 'sync_status', 'error'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(BacklogProject::class, 'backlog_project_id');
    }

    protected function casts(): array
    {
        return ['managed_dependencies' => 'array'];
    }
}
