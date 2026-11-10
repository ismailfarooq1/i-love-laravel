<?php

namespace App\Models;

use Database\Factories\GitHubConnectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GitHubConnection extends Model
{
    /** @use HasFactory<GitHubConnectionFactory> */
    use HasFactory;

    protected $fillable = ['team_id', 'user_id', 'mode', 'slot', 'github_user_id', 'login', 'access_token', 'refresh_token', 'expires_at', 'installation_id', 'revoked'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return ['access_token' => 'encrypted', 'refresh_token' => 'encrypted', 'expires_at' => 'datetime', 'revoked' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
