<?php

use App\Http\Controllers\AiPlanningController;
use App\Http\Controllers\AuthenticationController;
use App\Http\Controllers\GitHubConnectionController;
use App\Http\Controllers\GitHubPublicationController;
use App\Http\Controllers\GitHubWebhookController;
use App\Http\Controllers\ProjectController;
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
    Route::get('/projects/{project}/ai', [AiPlanningController::class, 'index'])->name('ai.index');
    Route::put('/projects/{project}/ai/connection', [AiPlanningController::class, 'connection'])->middleware('throttle:10,1')->name('ai.connection');
    Route::delete('/projects/{project}/ai/connection/{connection}', [AiPlanningController::class, 'disconnect'])->name('ai.disconnect');
    Route::post('/projects/{project}/ai/analyses', [AiPlanningController::class, 'store'])->middleware('throttle:5,1')->name('ai.analyze');
    Route::post('/projects/{project}/ai/analyses/{analysis}/apply', [AiPlanningController::class, 'update'])->name('ai.apply');
    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');
    Route::post('/projects/{project}/select', [ProjectController::class, 'update'])->name('projects.select');
    Route::get('/github/callback', [GitHubConnectionController::class, 'callback'])->name('github.callback');
    Route::get('/github/installed', [GitHubConnectionController::class, 'installed'])->name('github.installed');
    Route::get('/projects/{project}/github/connections', [GitHubConnectionController::class, 'index'])->name('github.connections');
    Route::post('/projects/{project}/github/connect', [GitHubConnectionController::class, 'store'])->name('github.connect');
    Route::post('/projects/{project}/github/{connection}/install', [GitHubConnectionController::class, 'install'])->name('github.install');
    Route::get('/projects/{project}/github/{connection}/repositories', [GitHubConnectionController::class, 'repositories'])->name('github.repositories');
    Route::put('/projects/{project}/github', [GitHubConnectionController::class, 'update'])->name('github.bind');
    Route::delete('/projects/{project}/github', [GitHubConnectionController::class, 'destroy'])->name('github.disconnect');
    Route::get('/projects/{project}/publications', [GitHubPublicationController::class, 'index'])->name('github.publications');
    Route::post('/projects/{project}/publications', [GitHubPublicationController::class, 'store'])->name('github.preview');
    Route::post('/projects/{project}/publications/{publication}/approve', [GitHubPublicationController::class, 'update'])->name('github.publish');
    Route::post('/projects/{project}/github/synchronize', [GitHubPublicationController::class, 'synchronize'])->name('github.synchronize');
    Route::post('/projects/{project}/github/issues/{issue}/retry-creation', [GitHubPublicationController::class, 'retryCreation'])->name('github.retryCreation');
    Route::prefix('workspace')->name('workspace.')->group(function (): void {
        Route::get('/', [WorkspaceController::class, 'show'])->name('show');
        Route::post('/reset', [WorkspaceController::class, 'store'])->name('reset');
        Route::post('/commands', [WorkspaceController::class, 'update'])->name('commands');
        Route::post('/documents', [WorkspaceController::class, 'document'])->name('documents');
    });
});

Route::post('/github/webhook', GitHubWebhookController::class)->name('github.webhook');
