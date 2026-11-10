<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAnalysis extends Model
{
    use HasFactory;

    protected $fillable = ['backlog_project_id', 'user_id', 'ai_connection_id', 'connection_revision', 'provider', 'model', 'generation', 'project_version', 'parent_node_id', 'status', 'input', 'result', 'usage', 'error', 'started_at', 'finished_at', 'applied_at'];

    protected function casts(): array
    {
        return ['input' => 'array', 'result' => 'array', 'usage' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'applied_at' => 'datetime', 'project_version' => 'integer', 'connection_revision' => 'integer'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(BacklogProject::class, 'backlog_project_id');
    }
}
