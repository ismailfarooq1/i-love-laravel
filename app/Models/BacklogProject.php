<?php

namespace App\Models;

use Database\Factories\BacklogProjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;

class BacklogProject extends Model
{
    /** @use HasFactory<BacklogProjectFactory> */
    use HasFactory;

    protected $fillable = ['workspace', 'version', 'state', 'team_id', 'name', 'description', 'generation', 'connection_mode', 'repository_id', 'repository_full_name', 'repository_private', 'github_connection_id', 'webhook_id'];

    protected function casts(): array
    {
        return ['state' => 'array', 'version' => 'integer', 'repository_private' => 'boolean'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    protected static function booted(): void
    {
        static::creating(function (self $project): void {
            $project->generation ??= (string) Str::uuid();
        });
    }

    public function githubIssues(): HasMany
    {
        return $this->hasMany(GitHubIssue::class)->whereExists(function (Builder $query): void {
            $query->selectRaw('1')->from('backlog_projects as current_project')
                ->whereColumn('current_project.id', 'git_hub_issues.backlog_project_id')
                ->whereColumn('current_project.generation', 'git_hub_issues.generation');
        });
    }

    public function githubConnection(): BelongsTo
    {
        return $this->belongsTo(GitHubConnection::class);
    }

    public function publications(): HasMany
    {
        return $this->hasMany(GitHubPublication::class);
    }
}
