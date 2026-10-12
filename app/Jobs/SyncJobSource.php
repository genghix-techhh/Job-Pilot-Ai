<?php

namespace App\Jobs;

use App\Models\JobSource;
use App\Services\Jobs\JobSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/** Syncs ONE source. Idempotent: re-running it only re-sights the same jobs. */
class SyncJobSource implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public int $sourceId, public string $trigger = 'schedule') {}

    /** @return list<object> */
    public function middleware(): array
    {
        // Two syncs of the same source must never run at once.
        return [(new WithoutOverlapping("sync-source-{$this->sourceId}"))->releaseAfter(60)->expireAfter(600)];
    }

    public function handle(JobSyncService $sync): void
    {
        $source = JobSource::find($this->sourceId);
        if ($source && ($source->enabled || $this->trigger === 'manual')) {
            $sync->sync($source, $this->trigger);
        }
    }
}
