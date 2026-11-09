<?php

namespace Database\Seeders;

use App\Models\Team;
use App\Services\BacklogWorkflow;
use Illuminate\Database\Seeder;

class BacklogProjectSeeder extends Seeder
{
    public function run(BacklogWorkflow $workflow): void
    {
        Team::with('owner')->whereDoesntHave('project')->each(function (Team $team) use ($workflow): void {
            $workflow->reset($team, $team->owner, null);
        });
    }
}
