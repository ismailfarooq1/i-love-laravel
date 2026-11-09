<?php

namespace Database\Factories;

use App\Models\BacklogProject;
use App\Services\DemoScenario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BacklogProject> */
class BacklogProjectFactory extends Factory
{
    public function definition(): array
    {
        return ['workspace' => 'local-demo', 'version' => 1, 'state' => (new DemoScenario)->initial()];
    }
}
