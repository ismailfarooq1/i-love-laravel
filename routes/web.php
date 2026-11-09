<?php

use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::prefix('workspace')->name('workspace.')->group(function (): void {
    Route::get('/', [WorkspaceController::class, 'show'])->name('show');
    Route::post('/reset', [WorkspaceController::class, 'store'])->name('reset');
    Route::post('/commands', [WorkspaceController::class, 'update'])->name('commands');
    Route::post('/documents', [WorkspaceController::class, 'document'])->name('documents');
});
