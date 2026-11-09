<?php

use App\Http\Controllers\AuthenticationController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TeamInvitationController;
use App\Http\Controllers\TeamMemberController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticationController::class, 'show'])->name('login');
    Route::post('/login', [AuthenticationController::class, 'login'])->middleware('throttle:auth-login')->name('login.store');
    Route::get('/register', [AuthenticationController::class, 'show'])->name('register');
    Route::post('/register', [AuthenticationController::class, 'register'])->middleware('throttle:6,1')->name('register.store');
    Route::get('/forgot-password', [AuthenticationController::class, 'show'])->name('password.request');
    Route::post('/forgot-password', [AuthenticationController::class, 'forgotPassword'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [AuthenticationController::class, 'show'])->name('password.reset');
    Route::post('/reset-password', [AuthenticationController::class, 'resetPassword'])->middleware('throttle:5,1')->name('password.update');
});

Route::middleware(['auth', 'auth.session'])->group(function (): void {
    Route::post('/logout', [AuthenticationController::class, 'logout'])->name('logout');
    Route::get('/email/verify', [AuthenticationController::class, 'show'])->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', [AuthenticationController::class, 'verify'])->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/email/verification-notification', [AuthenticationController::class, 'resend'])->middleware('throttle:6,1')->name('verification.send');
});

Route::middleware(['auth', 'auth.session', 'verified'])->group(function (): void {
    Route::view('/', 'welcome')->name('home');
    Route::get('/teams', [TeamController::class, 'index'])->name('teams.index');
    Route::post('/teams', [TeamController::class, 'store'])->name('teams.store');
    Route::post('/teams/{team}/select', [TeamController::class, 'update'])->name('teams.select');
    Route::patch('/teams/{team}/members/{user}', [TeamMemberController::class, 'update'])->name('teams.members.update');
    Route::delete('/teams/{team}/members/{user}', [TeamMemberController::class, 'destroy'])->name('teams.members.destroy');
    Route::post('/teams/{team}/invitations', [TeamInvitationController::class, 'store'])->name('teams.invitations.store');
    Route::delete('/teams/{team}/invitations/{invitation}', [TeamInvitationController::class, 'destroy'])->name('teams.invitations.destroy');
    Route::get('/invitations/{token}', fn (string $token): View => view('auth', ['screen' => 'invitation', 'token' => $token, 'email' => '']))->name('invitations.show');
    Route::get('/invitations/{token}/details', [TeamInvitationController::class, 'show'])->name('invitations.details');
    Route::post('/invitations/{token}', [TeamInvitationController::class, 'update'])->name('invitations.accept');
    Route::prefix('workspace')->name('workspace.')->group(function (): void {
        Route::get('/', [WorkspaceController::class, 'show'])->name('show');
        Route::post('/reset', [WorkspaceController::class, 'store'])->name('reset');
        Route::post('/commands', [WorkspaceController::class, 'update'])->name('commands');
        Route::post('/documents', [WorkspaceController::class, 'document'])->name('documents');
    });
});
