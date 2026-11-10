<?php

namespace App\Services;

use App\Models\BacklogProject;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ProjectAccess
{
    public function current(Request $request, Team $team): ?BacklogProject
    {
        $id = $request->header('X-Project-ID', $request->session()->get('project_id.'.$team->id));
        if ($id !== null) {
            abort_unless(filter_var($id, FILTER_VALIDATE_INT) !== false, 404);
            $project = BacklogProject::where('team_id', $team->id)->whereKey($id)->first();
            abort_if(! $project && $request->hasHeader('X-Project-ID'), 404);
            if ($project) {
                return $project;
            }
        }

        return BacklogProject::where('team_id', $team->id)->orderBy('id')->first();
    }

    public function authorize(BacklogProject $project, string $ability = 'view'): void
    {
        abort_unless($project->team_id !== null, 404);
        Gate::authorize('view', $project->team);
        Gate::authorize($ability, $project->team);
    }
}
