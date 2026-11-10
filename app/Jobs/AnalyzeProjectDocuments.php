<?php

namespace App\Jobs;

use App\Models\AiAnalysis;
use App\Services\AiPlanning;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class AnalyzeProjectDocuments implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $analysisId) {}

    public function handle(AiPlanning $planning): void
    {
        $planning->analyze($this->analysisId);
    }

    public function failed(?Throwable $exception): void
    {
        AiAnalysis::whereKey($this->analysisId)->whereIn('status', ['queued', 'processing'])->update(['status' => 'failed', 'error' => 'Analysis was interrupted. Start a new analysis when you are ready. No proposals were added.', 'finished_at' => now()]);
    }
}
