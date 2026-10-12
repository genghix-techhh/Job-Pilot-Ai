<?php

namespace App\Jobs;

use App\Services\Jobs\JobSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExpireStaleJobs implements ShouldQueue
{
    use Queueable;

    public function handle(JobSyncService $sync): void
    {
        $sync->expireStale();
    }
}
