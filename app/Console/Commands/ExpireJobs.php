<?php

namespace App\Console\Commands;

use App\Services\Jobs\JobSyncService;
use Illuminate\Console\Command;

class ExpireJobs extends Command
{
    protected $signature = 'app:expire-jobs';

    protected $description = 'Mark jobs not seen for JOB_EXPIRY_DAYS (or past their expiry date) as expired';

    public function handle(JobSyncService $sync): int
    {
        $n = $sync->expireStale();
        $this->info("Expired {$n} job(s).");

        return self::SUCCESS;
    }
}
