<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guests_cannot_read_or_mutate_workspaces_or_memberships(): void
    {
        $this->get('/')->assertRedirectToRoute('login');
        $this->getJson(route('workspace.show'))->assertUnauthorized();
        $this->postJson(route('workspace.reset'), ['confirmation' => 'RESET'])->assertUnauthorized();
        $this->postJson(route('workspace.commands'), ['action' => 'add_goal'])->assertUnauthorized();
        $this->getJson(route('teams.index'))->assertUnauthorized();
        $this->postJson(route('teams.store'), ['name' => 'Unauthorized'])->assertUnauthorized();
        $this->assertDatabaseCount('teams', 0);
    }

    public function test_registration_hashes_password_creates_owned_team_and_requests_email_verification(): void
    {
        Notification::fake();

        $this->postJson(route('register.store'), ['name' => 'Alice', 'email' => 'ALICE@example.com', 'password' => 'long-secure-password', 'password_confirmation' => 'long-secure-password', 'role' => 'admin'])
            ->assertCreated()->assertJsonPath('redirect', route('verification.notice'));

        $user = User::where('email', 'alice@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Hash::check('long-secure-password', $user->password));
        $this->assertNull($user->email_verified_at);
        $this->assertDatabaseHas('teams', ['owner_id' => $user->id]);
        $this->assertDatabaseHas('team_user', ['user_id' => $user->id, 'role' => 'owner']);
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->getJson(route('workspace.show'))->assertForbidden();
    }

    public function test_registration_rejects_short_passwords_and_duplicate_email(): void
    {
        User::factory()->create(['email' => 'alice@example.com']);

        $this->postJson(route('register.store'), ['name' => 'Alice', 'email' => 'alice@example.com', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);

        $this->assertDatabaseCount('teams', 0);
    }

    public function test_login_and_logout_use_the_session_guard(): void
    {
        $user = User::factory()->create(['password' => 'long-secure-password']);

        $this->postJson(route('login.store'), ['email' => $user->email, 'password' => 'long-secure-password'])->assertOk()->assertJsonPath('redirect', route('home'));
        $this->assertAuthenticatedAs($user);
        $this->postJson(route('logout'))->assertOk()->assertJsonPath('redirect', route('login'));

        $this->assertGuest();
        $this->getJson(route('workspace.show'))->assertUnauthorized();
    }

    public function test_failed_login_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson(route('login.store'), ['email' => 'missing@example.com', 'password' => 'invalid-password'])->assertUnprocessable();
        }

        $this->postJson(route('login.store'), ['email' => 'missing@example.com', 'password' => 'invalid-password'])->assertTooManyRequests();

        $this->assertGuest();
    }

    public function test_login_ignores_empty_registration_and_reset_fields_sent_by_the_browser(): void
    {
        $user = User::factory()->create(['password' => 'long-secure-password']);

        $this->postJson(route('login.store'), [
            'email' => $user->email, 'password' => 'long-secure-password',
            'name' => '', 'password_confirmation' => '', 'token' => null,
        ])->assertOk()->assertJsonPath('redirect', route('home'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_password_recovery_ignores_empty_fields_from_other_auth_forms(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->postJson(route('password.email'), [
            'email' => $user->email, 'name' => '', 'password' => '',
            'password_confirmation' => '', 'token' => null,
        ])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_signed_email_verification_is_bound_to_the_authenticated_user(): void
    {
        $user = User::factory()->unverified()->create();
        $other = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->actingAs($other)->get($url)->assertForbidden();

        $this->actingAs($user)->get($url)->assertRedirectToRoute('home');

        $this->assertNotNull($user->refresh()->email_verified_at);
        $this->assertNull($other->refresh()->email_verified_at);
    }

    public function test_forgot_password_is_generic_and_reset_requires_a_valid_token(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->postJson(route('password.email'), ['email' => 'missing@example.com'])->assertOk()->assertJsonPath('message', 'If that email is registered, a password reset link has been sent.');
        $this->postJson(route('password.email'), ['email' => $user->email])->assertOk();
        Notification::assertSentTo($user, ResetPassword::class);
        $this->postJson(route('password.update'), ['email' => $user->email, 'token' => 'invalid', 'password' => 'updated-secure-password', 'password_confirmation' => 'updated-secure-password'])->assertUnprocessable();
        $token = Password::createToken($user);

        $this->postJson(route('password.update'), ['email' => $user->email, 'token' => $token, 'password' => 'updated-secure-password', 'password_confirmation' => 'updated-secure-password'])->assertOk();

        $this->assertTrue(Hash::check('updated-secure-password', $user->refresh()->password));
        $this->postJson(route('password.update'), ['email' => $user->email, 'token' => $token, 'password' => 'another-secure-password', 'password_confirmation' => 'another-secure-password'])->assertUnprocessable();
    }

    public function test_team_members_are_not_granted_global_filament_access(): void
    {
        $team = Team::factory()->create();

        $this->actingAs($team->owner)->get('/admin')->assertForbidden();
    }

    public function test_default_seeding_does_not_create_shared_login_credentials(): void
    {
        $this->artisan('db:seed', ['--no-interaction' => true])->assertSuccessful();

        $this->assertDatabaseCount('users', 0);
    }
}
