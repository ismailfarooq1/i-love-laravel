<?php

namespace App\Services;

use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TeamAccess
{
    public function current(Request $request): Team
    {
        $id = $request->header('X-Team-ID', $request->session()->get('team_id'));
        if ($id === null) {
            $team = $request->user()->teams()->orderBy('teams.id')->firstOrFail();
        } else {
            abort_unless(filter_var($id, FILTER_VALIDATE_INT) !== false, 404);
            $team = $request->user()->teams()->whereKey($id)->first();
            if (! $team && ! $request->hasHeader('X-Team-ID')) {
                $request->session()->forget('team_id');
                $team = $request->user()->teams()->orderBy('teams.id')->first();
            }
            abort_unless($team !== null, 404);
        }
        Gate::authorize('view', $team);

        return $team;
    }
}
