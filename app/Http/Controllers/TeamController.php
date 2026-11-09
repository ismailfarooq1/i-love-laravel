<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\User;
use App\Services\TeamAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class TeamController extends Controller
{
    public function index(Request $request, TeamAccess $access): JsonResponse
    {
        if (! $request->user()->teams()->exists()) {
            return response()->json(['user' => $request->user()->only(['id', 'name', 'email']), 'teams' => [], 'team' => null, 'role' => null, 'permissions' => ['edit' => false, 'review' => false, 'publish' => false, 'manage' => false], 'members' => [], 'invitations' => []]);
        }
        $team = $access->current($request);
        $role = $team->roleFor($request->user());
        $members = $team->members()->orderBy('users.name')->get()->map(fn (User $member): array => ['id' => $member->id, 'name' => $member->name, 'email' => $member->email, 'role' => $member->pivot->role]);

        return response()->json([
            'user' => $request->user()->only(['id', 'name', 'email']),
            'teams' => $request->user()->teams()->orderBy('teams.id')->get(['teams.id', 'teams.name'])->map(fn (Team $item): array => ['id' => $item->id, 'name' => $item->name]),
            'team' => $team->only(['id', 'name', 'owner_id']), 'role' => $role->value,
            'permissions' => ['edit' => $role->canEdit(), 'review' => $role->canReview(), 'publish' => $role->canReview(), 'manage' => $role->canManage()],
            'members' => $members,
            'invitations' => $role->canManage() ? $team->invitations()->whereNull('accepted_at')->where('expires_at', '>', now())->orderByDesc('id')->get(['id', 'email', 'role', 'expires_at']) : [],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $team = DB::transaction(function () use ($request, $data): Team {
            $team = Team::create(['name' => $data['name'], 'owner_id' => $request->user()->id]);
            $team->members()->attach($request->user()->id, ['role' => 'owner']);

            return $team;
        });
        $request->session()->put('team_id', $team->id);

        return response()->json(['team_id' => $team->id], 201);
    }

    public function update(Request $request, Team $team): JsonResponse
    {
        Gate::authorize('view', $team);
        $request->session()->put('team_id', $team->id);

        return response()->json(['team_id' => $team->id]);
    }
}
