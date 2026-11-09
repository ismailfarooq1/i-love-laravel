<?php

namespace App\Models;

use App\TeamRole;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    protected $fillable = ['name', 'owner_id'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function project(): HasOne
    {
        return $this->hasOne(BacklogProject::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    public function roleFor(User $user): ?TeamRole
    {
        if ((int) $this->owner_id === $user->id) {
            return TeamRole::Owner;
        }
        $role = $this->members()->whereKey($user->id)->value('team_user.role');

        return $role !== null ? TeamRole::tryFrom($role) : null;
    }
}
