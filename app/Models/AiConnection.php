<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiConnection extends Model
{
    use HasFactory;

    protected $fillable = ['team_id', 'provider', 'model', 'api_key', 'revision', 'user_id', 'slot', 'auth_type', 'shared_with_team', 'credentials'];

    protected $hidden = ['api_key', 'credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'api_key' => 'encrypted', 'revision' => 'integer', 'shared_with_team' => 'boolean'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $query->where('provider', 'chatgpt')->where('user_id', $user->id);
    }

    public function usableBy(User $user): bool
    {
        return $this->provider === 'chatgpt' && $this->user_id === $user->id
            && $this->connected() && $this->model !== ''
            && (Team::find($this->team_id)?->roleFor($user)?->canEdit() ?? false);
    }

    public function connected(): bool
    {
        return $this->provider === 'chatgpt' && ! empty($this->credentials['access_token'])
            && in_array('chatgpt.tokens.use.direct', $this->credentials['scopes'] ?? [], true);
    }
}
