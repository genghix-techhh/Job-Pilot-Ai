<?php

namespace App\Jobs;

use App\Models\JobPosting;
use App\Services\Jobs\JobSyncService;
use App\Services\Matching\MatchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Re-scores active jobs after the profile, weights or thresholds changed. Cache-aware and cheap (no AI calls here). */
class RecalculateMatches implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 280;

    public function __construct(public bool $notify = false) {}

    public function handle(MatchService $matches, JobSyncService $sync): void
    {
        $matches->reset();
        $all = [];
        JobPosting::query()->where('state', 'active')->whereNull('duplicate_of')
            ->orderByDesc('id')->chunk(200, function ($chunk) use ($matches, &$all) {
                foreach ($matches->scoreMany($chunk, $this->notify) as $m) {
                    $all[] = $m;
                }
            });

        $sync->queueAiAnalysis($all);
    }
}
