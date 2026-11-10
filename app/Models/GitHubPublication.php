<?php

namespace App\Models;

use Database\Factories\GitHubPublicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GitHubPublication extends Model
{
    /** @use HasFactory<GitHubPublicationFactory> */
    use HasFactory;

    protected $fillable = ['backlog_project_id', 'user_id', 'github_connection_id', 'generation', 'repository_id', 'repository_full_name', 'status', 'items', 'error', 'approved_at'];

    protected function casts(): array
    {
        return ['items' => 'array', 'approved_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(BacklogProject::class, 'backlog_project_id');
    }
}
