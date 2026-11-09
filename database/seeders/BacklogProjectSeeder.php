<?php

namespace Database\Seeders;

use App\Models\BacklogProject;
use App\Services\BacklogWorkflow;
use Illuminate\Database\Seeder;

class BacklogProjectSeeder extends Seeder
{
    public function run(BacklogWorkflow $workflow): void
    {
        if (! BacklogProject::where('workspace', 'local-demo')->exists()) {
            $workflow->reset(null);
        }
    }
}
