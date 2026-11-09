<?php

namespace App\Models;

use Database\Factories\BacklogProjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BacklogProject extends Model
{
    /** @use HasFactory<BacklogProjectFactory> */
    use HasFactory;

    protected $fillable = ['workspace', 'version', 'state', 'team_id'];

    protected function casts(): array
    {
        return ['state' => 'array', 'version' => 'integer'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
