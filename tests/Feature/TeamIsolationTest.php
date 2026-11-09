<?php

namespace Tests\Feature;

use App\Models\BacklogProject;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\BacklogWorkflow;
use App\TeamRole;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TeamIsolationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_team_header_and_switching_cannot_access_another_tenant(): void
    {
        $team = Team::factory()->create();
        $other = Team::factory()->create();
        $workflow = app(BacklogWorkflow::class);
        $project = $workflow->reset($other, $other->owner, null);
        $before = $project->state;
        $this->actingAs($team->owner)->withSession(['team_id' => $team->id]);

        $this->getJson(route('workspace.show'), ['X-Team-ID' => $other->id])->assertNotFound();
        $this->postJson(route('workspace.reset'), ['confirmation' => 'RESET'], ['X-Team-ID' => $other->id])->assertNotFound();
        $this->postJson(route('workspace.commands'), ['version' => 1, 'action' => 'reject', 'node_id' => 'root'], ['X-Team-ID' => $other->id])->assertNotFound();
        $this->postJson(route('teams.select', $other))->assertNotFound();
        $this->patchJson(route('teams.members.update', [$other, $other->owner]), ['role' => 'viewer'])->assertNotFound();
        $this->postJson(route('teams.invitations.store', $other), ['email' => 'new@example.com', 'role' => 'viewer'])->assertNotFound();

        $this->assertSame($before, $project->refresh()->state);
        $this->getJson(route('teams.index'))->assertJsonCount(1, 'teams')->assertJsonPath('team.id', $team->id);
    }

    public function test_legacy_workspace_is_not_exposed_or_claimed_by_a_new_team(): void
    {
        $legacy = BacklogProject::factory()->create();
        $team = Team::factory()->create();
        $this->actingAs($team->owner)->withSession(['team_id' => $team->id]);

        $this->getJson(route('workspace.show'))->assertJsonPath('state', null);
        $this->postJson(route('workspace.reset'), ['confirmation' => 'RESET'])->assertOk();

        $this->assertNull($legacy->refresh()->team_id);
        $this->assertDatabaseCount('backlog_projects', 2);
    }

    public function test_explicit_team_headers_keep_stale_tabs_in_their_original_tenant(): void
    {
        $first = Team::factory()->create();
        $second = Team::factory()->for($first->owner, 'owner')->create();
        $workflow = app(BacklogWorkflow::class);
        $firstProject = $workflow->reset($first, $first->owner, null);
        $secondProject = $workflow->reset($second, $second->owner, null);
        $this->actingAs($first->owner)->withSession(['team_id' => $first->id]);
        $this->postJson(route('teams.select', $second))->assertOk();

        $this->postJson(route('workspace.commands'), ['version' => 1, 'action' => 'add_goal', 'title' => 'Original tab', 'description' => 'Still the first team.', 'team_id' => $second->id], ['X-Team-ID' => $first->id])->assertOk();

        $this->assertSame(2, $firstProject->refresh()->version);
        $this->assertSame(1, $secondProject->refresh()->version);
    }

    public static function roles(): array
    {
        return [
            'owner' => [TeamRole::Owner, true, true, true],
            'admin' => [TeamRole::Admin, true, true, true],
            'reviewer' => [TeamRole::Reviewer, true, true, false],
            'editor' => [TeamRole::Editor, true, false, false],
            'viewer' => [TeamRole::Viewer, false, false, false],
        ];
    }

    #[DataProvider('roles')]
    public function test_role_policy_matrix(TeamRole $role, bool $edit, bool $review, bool $manage): void
    {
        $team = Team::factory()->create();
        $user = $role === TeamRole::Owner ? $team->owner : User::factory()->create();
        if ($role !== TeamRole::Owner) {
            $team->members()->attach($user, ['role' => $role->value]);
        }

        $gate = Gate::forUser($user);

        $this->assertTrue($gate->allows('view', $team));
        $this->assertSame($edit, $gate->allows('edit', $team));
        $this->assertSame($review, $gate->allows('review', $team));
        $this->assertSame($review, $gate->allows('publish', $team));
        $this->assertSame($manage, $gate->allows('manageMembers', $team));
        $this->assertSame($manage, $gate->allows('reset', $team));
    }

    public function test_viewer_cannot_mutate_any_workspace_boundary(): void
    {
        $team = Team::factory()->create();
        $project = app(BacklogWorkflow::class)->reset($team, $team->owner, null);
        $viewer = User::factory()->create();
        $team->members()->attach($viewer, ['role' => 'viewer']);
        $this->actingAs($viewer)->withSession(['team_id' => $team->id]);
        $this->getJson(route('workspace.show'))->assertOk();
        foreach (['add_goal', 'approve', 'reject', 'resolve', 'complete', 'reopen', 'prepare_batch', 'publish', 'document'] as $action) {
            $this->postJson(route('workspace.commands'), ['version' => 1, 'action' => $action, 'node_id' => 'queue', 'title' => 'Changed', 'description' => 'Changed.', 'answer' => 'Approved.', 'node_ids' => ['queue'], 'batch_id' => 'missing', 'name' => 'source', 'content' => 'new source'])->assertForbidden();
        }
        $this->postJson(route('workspace.reset'), ['confirmation' => 'RESET'])->assertForbidden();
        $this->post(route('workspace.documents'), ['version' => 1, 'file' => UploadedFile::fake()->createWithContent('source.md', '# Hello')], ['Accept' => 'application/json'])->assertForbidden();
        $this->postJson(route('teams.invitations.store', $team), ['email' => 'new@example.com', 'role' => 'admin'])->assertForbidden();

        $this->assertSame(1, $project->refresh()->version);
    }

    public function test_editor_can_prepare_work_but_cannot_approve_publish_or_manage_members(): void
    {
        $team = Team::factory()->create();
        app(BacklogWorkflow::class)->reset($team, $team->owner, null);
        $editor = User::factory()->create(['name' => 'Real Editor']);
        $team->members()->attach($editor, ['role' => 'editor']);
        $this->actingAs($editor)->withSession(['team_id' => $team->id]);

        $this->postJson(route('workspace.commands'), ['version' => 1, 'action' => 'add_goal', 'title' => 'New objective', 'description' => 'A real author.'])->assertOk();
        $this->postJson(route('workspace.commands'), ['version' => 2, 'action' => 'approve', 'node_id' => 'reply'])->assertForbidden();
        $this->postJson(route('workspace.commands'), ['version' => 2, 'action' => 'prepare_batch', 'node_ids' => ['queue']])->assertForbidden();
        $this->patchJson(route('teams.members.update', [$team, $editor]), ['role' => 'admin'])->assertForbidden();

        $history = $team->project->state['history'];
        $event = $history[array_key_last($history)];
        $this->assertSame($editor->id, $event['actor_id']);
        $this->assertSame('Real Editor', $event['actor']);
    }

    public function test_revoking_membership_immediately_revokes_workspace_access(): void
    {
        $team = Team::factory()->create();
        $member = User::factory()->create();
        $team->members()->attach($member, ['role' => 'editor']);
        $this->actingAs($member)->withSession(['team_id' => $team->id]);
        $team->members()->detach($member);

        $this->getJson(route('workspace.show'), ['X-Team-ID' => $team->id])->assertNotFound();
        $this->postJson(route('workspace.reset'), ['confirmation' => 'RESET'], ['X-Team-ID' => $team->id])->assertNotFound();
    }

    public function test_owner_cannot_be_demoted_removed_or_impersonated_by_role_payload(): void
    {
        $team = Team::factory()->create();
        $this->actingAs($team->owner)->withSession(['team_id' => $team->id]);

        $this->patchJson(route('teams.members.update', [$team, $team->owner]), ['role' => 'viewer'])->assertUnprocessable();
        $this->deleteJson(route('teams.members.destroy', [$team, $team->owner]))->assertUnprocessable();
        $this->postJson(route('teams.invitations.store', $team), ['email' => 'new@example.com', 'role' => 'owner'])->assertUnprocessable();

        $this->assertDatabaseHas('team_user', ['team_id' => $team->id, 'user_id' => $team->owner_id, 'role' => 'owner']);
    }

    public function test_invitation_is_email_bound_single_use_and_never_exposes_stored_token_hash(): void
    {
        $team = Team::factory()->create();
        $invitee = User::factory()->create(['email' => 'invitee@example.com']);
        $this->actingAs($team->owner)->withSession(['team_id' => $team->id]);
        $response = $this->postJson(route('teams.invitations.store', $team), ['email' => $invitee->email, 'role' => 'editor'])->assertCreated();
        $token = basename(parse_url($response->json('url'), PHP_URL_PATH));
        $this->assertDatabaseMissing('team_invitations', ['token_hash' => $token]);
        $this->getJson(route('teams.index'))->assertJsonMissingPath('invitations.0.token_hash');
        $this->actingAs($team->owner)->postJson(route('invitations.accept', $token))->assertNotFound();
        $this->actingAs($invitee)->getJson(route('invitations.details', $token))->assertOk()->assertJsonPath('role', 'editor');

        $this->postJson(route('invitations.accept', $token), ['role' => 'owner'])->assertOk();

        $this->assertDatabaseHas('team_user', ['team_id' => $team->id, 'user_id' => $invitee->id, 'role' => 'editor']);
        $this->postJson(route('invitations.accept', $token))->assertNotFound();
    }

    public function test_expired_and_revoked_invitations_cannot_be_accepted(): void
    {
        $user = User::factory()->create();
        $invitation = TeamInvitation::factory()->create(['email' => $user->email, 'token_hash' => hash('sha256', 'expired'), 'expires_at' => now()->subMinute()]);
        $revoked = TeamInvitation::factory()->create(['email' => $user->email, 'token_hash' => hash('sha256', 'revoked')]);
        $revoked->delete();
        $this->actingAs($user);

        $this->postJson(route('invitations.accept', 'expired'))->assertNotFound();
        $this->postJson(route('invitations.accept', 'revoked'))->assertNotFound();

        $this->assertDatabaseMissing('team_user', ['team_id' => $invitation->team_id, 'user_id' => $user->id]);
    }

    public function test_legacy_import_is_explicit_and_refuses_to_overwrite_a_workspace(): void
    {
        $legacy = BacklogProject::factory()->create();
        $team = Team::factory()->create();

        $this->artisan('tracework:adopt-demo', ['team' => $team->id])->assertSuccessful();

        $this->assertSame($team->id, $legacy->refresh()->team_id);
        $this->artisan('tracework:adopt-demo', ['team' => $team->id])->assertFailed();
    }

    public function test_unverified_account_cannot_accept_even_a_matching_invitation(): void
    {
        $user = User::factory()->unverified()->create();
        $invitation = TeamInvitation::factory()->create(['email' => $user->email, 'token_hash' => hash('sha256', 'secret-link')]);

        $this->actingAs($user)->postJson(route('invitations.accept', 'secret-link'))->assertForbidden();

        $this->assertDatabaseMissing('team_user', ['team_id' => $invitation->team_id, 'user_id' => $user->id]);
        $this->assertNull($invitation->refresh()->accepted_at);
    }

    public function test_admin_role_changes_immediately_change_member_permissions(): void
    {
        $team = Team::factory()->create();
        app(BacklogWorkflow::class)->reset($team, $team->owner, null);
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $team->members()->attach($admin, ['role' => 'admin']);
        $team->members()->attach($member, ['role' => 'editor']);
        $this->actingAs($admin)->withSession(['team_id' => $team->id]);
        $this->patchJson(route('teams.members.update', [$team, $member]), ['role' => 'reviewer'])->assertOk();
        $this->actingAs($member)->postJson(route('workspace.commands'), ['version' => 1, 'action' => 'approve', 'node_id' => 'reply'])->assertOk();
        $this->actingAs($admin)->patchJson(route('teams.members.update', [$team, $member]), ['role' => 'viewer'])->assertOk();

        $this->actingAs($member)->postJson(route('workspace.commands'), ['version' => 2, 'action' => 'reject', 'node_id' => 'queue'])->assertForbidden();

        $this->assertDatabaseHas('team_user', ['team_id' => $team->id, 'user_id' => $member->id, 'role' => 'viewer']);
        $this->actingAs($admin)->deleteJson(route('teams.members.destroy', [$team, $member]))->assertOk();
        $this->actingAs($member)->getJson(route('workspace.show'), ['X-Team-ID' => $team->id])->assertNotFound();
    }
}
