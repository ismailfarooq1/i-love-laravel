<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TeamPolicy
{
    public function view(User $user, Team $team): Response
    {
        return $team->roleFor($user) !== null ? Response::allow() : Response::denyAsNotFound();
    }

    public function edit(User $user, Team $team): bool
    {
        return $team->roleFor($user)?->canEdit() ?? false;
    }

    public function review(User $user, Team $team): bool
    {
        return $team->roleFor($user)?->canReview() ?? false;
    }

    public function publish(User $user, Team $team): bool
    {
        return $this->review($user, $team);
    }

    public function manageMembers(User $user, Team $team): bool
    {
        return $team->roleFor($user)?->canManage() ?? false;
    }

    public function reset(User $user, Team $team): bool
    {
        return $this->manageMembers($user, $team);
    }
}
