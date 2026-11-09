<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TeamMemberController extends Controller
{
    public function update(Request $request, Team $team, User $user): JsonResponse
    {
        return DB::transaction(function () use ($request, $team, $user): JsonResponse {
            Team::whereKey($team->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('view', $team);
            Gate::authorize('manageMembers', $team);
            abort_unless($team->members()->whereKey($user->id)->exists(), 404);
            abort_if((int) $team->owner_id === $user->id, 422, 'The team owner cannot be demoted.');
            $data = $request->validate(['role' => ['required', Rule::in(['admin', 'reviewer', 'editor', 'viewer'])]]);
            $team->members()->updateExistingPivot($user->id, ['role' => $data['role']]);

            return response()->json(['message' => 'Member role updated.']);
        });
    }

    public function destroy(Request $request, Team $team, User $user): JsonResponse
    {
        return DB::transaction(function () use ($request, $team, $user): JsonResponse {
            Team::whereKey($team->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('view', $team);
            if ($request->user()->id !== $user->id) {
                Gate::authorize('manageMembers', $team);
            }
            abort_unless($team->members()->whereKey($user->id)->exists(), 404);
            abort_if((int) $team->owner_id === $user->id, 422, 'The owner cannot leave the team.');
            $team->members()->detach($user->id);

            return response()->json(['message' => 'Membership removed.']);
        });
    }
}
