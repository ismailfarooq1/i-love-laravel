<?php

namespace App\Console\Commands;

use App\Models\BacklogProject;
use App\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AdoptLegacyWorkspace extends Command
{
    protected $signature = 'tracework:adopt-demo {team : The numeric ID of the team that should receive the legacy workspace}';

    protected $description = 'Assign the unowned legacy demo to a specific team without exposing it to other accounts';

    public function handle(): int
    {
        return DB::transaction(function (): int {
            $team = Team::whereKey($this->argument('team'))->lockForUpdate()->first();
            if (! $team) {
                $this->error('Team not found.');

                return self::FAILURE;
            }
            if ($team->project()->exists()) {
                $this->error('The team already has a workspace. Nothing was overwritten.');

                return self::FAILURE;
            }
            $project = BacklogProject::where('workspace', 'local-demo')->whereNull('team_id')->lockForUpdate()->first();
            if (! $project) {
                $this->error('No unassigned legacy demo was found.');

                return self::FAILURE;
            }
            $project->update(['team_id' => $team->id, 'workspace' => 'team-'.$team->id]);
            $this->info('Legacy workspace assigned to '.$team->name.' (team '.$team->id.').');

            return self::SUCCESS;
        });
    }
}
