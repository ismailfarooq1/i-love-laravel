<?php

namespace App\Http\Controllers;

use App\Http\Requests\AuthenticationRequest;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticationController extends Controller
{
    public function show(Request $request): View
    {
        return view('auth', ['screen' => $request->route()->getName(), 'token' => $request->route('token'), 'email' => $request->query('email', '')]);
    }

    public function register(AuthenticationRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request): User {
            $user = User::create($request->safe()->only(['name', 'email', 'password']));
            $team = Team::create(['name' => $user->name.'’s team', 'owner_id' => $user->id]);
            $team->members()->attach($user->id, ['role' => 'owner']);
            $request->session()->put('team_id', $team->id);

            return $user;
        });
        Auth::login($user);
        $request->session()->regenerate();
        event(new Registered($user));

        return response()->json(['redirect' => route('verification.notice')], 201);
    }

    public function login(AuthenticationRequest $request): JsonResponse
    {
        if (! Auth::attempt($request->safe()->only(['email', 'password']))) {
            throw ValidationException::withMessages(['email' => 'The supplied credentials are incorrect.']);
        }
        $request->session()->regenerate();
        $request->session()->forget('team_id');

        return response()->json(['redirect' => $request->user()->hasVerifiedEmail() ? $request->session()->pull('url.intended', route('home')) : route('verification.notice')]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['redirect' => route('login')]);
    }

    public function forgotPassword(AuthenticationRequest $request): JsonResponse
    {
        Password::sendResetLink($request->safe()->only(['email']));

        return response()->json(['message' => 'If that email is registered, a password reset link has been sent.']);
    }

    public function resetPassword(AuthenticationRequest $request): JsonResponse
    {
        $status = Password::reset($request->safe()->only(['email', 'password', 'password_confirmation', 'token']), function (User $user, string $password): void {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            event(new PasswordReset($user));
        });
        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return response()->json(['redirect' => route('login')]);
    }

    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        $request->fulfill();

        return redirect()->intended(route('home'));
    }

    public function resend(Request $request): JsonResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->sendEmailVerificationNotification();
        }

        return response()->json(['message' => 'A verification link has been sent.']);
    }
}
