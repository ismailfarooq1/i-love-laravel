<?php

use App\Jobs\SynchronizeGitHubIssues;
use App\Models\BacklogProject;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    BacklogProject::whereNotNull('github_connection_id')->whereHas('githubIssues', fn ($query) => $query->whereNotNull('github_id'))->orderBy('id')->each(function (BacklogProject $project): void {
        SynchronizeGitHubIssues::dispatch($project->id);
    });
})->name('github-reconciliation')->everyFiveMinutes()->withoutOverlapping();
