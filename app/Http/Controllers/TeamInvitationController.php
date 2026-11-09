<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\TeamInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TeamInvitationController extends Controller
{
    public function store(Request $request, Team $team): JsonResponse
    {
        return DB::transaction(function () use ($request, $team): JsonResponse {
            Team::whereKey($team->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('view', $team);
            Gate::authorize('manageMembers', $team);
            $data = $request->validate(['email' => ['required', 'email', 'lowercase', 'max:254'], 'role' => ['required', Rule::in(['admin', 'reviewer', 'editor', 'viewer'])]]);
            abort_if($team->members()->where('email', $data['email'])->exists(), 422, 'This person is already a team member.');
            $team->invitations()->where('email', $data['email'])->whereNull('accepted_at')->delete();
            $token = Str::random(64);
            $invitation = $team->invitations()->create([...$data, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7)]);

            return response()->json(['id' => $invitation->id, 'url' => route('invitations.show', ['token' => $token]), 'expires_at' => $invitation->expires_at], 201);
        });
    }

    public function show(Request $request, string $token): JsonResponse
    {
        $invitation = TeamInvitation::where('token_hash', hash('sha256', $token))->whereNull('accepted_at')->where('expires_at', '>', now())->firstOrFail();
        abort_unless(hash_equals($invitation->email, $request->user()->email), 404);

        return response()->json(['team' => $invitation->team->name, 'role' => $invitation->role]);
    }

    public function update(Request $request, string $token): JsonResponse
    {
        return DB::transaction(function () use ($request, $token): JsonResponse {
            $candidate = TeamInvitation::where('token_hash', hash('sha256', $token))->firstOrFail();
            Team::whereKey($candidate->team_id)->lockForUpdate()->firstOrFail();
            $invitation = TeamInvitation::whereKey($candidate->id)->lockForUpdate()->whereNull('accepted_at')->where('expires_at', '>', now())->firstOrFail();
            abort_unless(hash_equals($invitation->email, $request->user()->email), 404);
            if (! $invitation->team->members()->whereKey($request->user()->id)->exists()) {
                $invitation->team->members()->attach($request->user()->id, ['role' => $invitation->role]);
            }
            $invitation->update(['accepted_at' => now()]);
            $request->session()->put('team_id', $invitation->team_id);

            return response()->json(['redirect' => route('home')]);
        });
    }

    public function destroy(Team $team, TeamInvitation $invitation): JsonResponse
    {
        return DB::transaction(function () use ($team, $invitation): JsonResponse {
            Team::whereKey($team->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('view', $team);
            Gate::authorize('manageMembers', $team);
            abort_unless($invitation->team_id === $team->id, 404);
            $invitation->delete();

            return response()->json(['message' => 'Invitation revoked.']);
        });
    }
}
