<?php

namespace App\Console\Commands;

use App\Models\JobSource;
use App\Services\Jobs\JobSyncService;
use Illuminate\Console\Command;

class SyncJobSources extends Command
{
    protected $signature = 'app:sync-job-sources
        {source? : Source id (default: all enabled sources)}
        {--due : Only sources whose next sync time has passed}
        {--force : Ignore the schedule and sync now}';

    protected $description = 'Fetch jobs from enabled sources. One failing source never stops the others.';

    public function handle(JobSyncService $sync): int
    {
        if ($id = $this->argument('source')) {
            $source = JobSource::find($id);
            if (! $source) {
                $this->error("Source #{$id} not found.");

                return self::FAILURE;
            }
            $runs = [$sync->sync($source, 'manual')];
        } else {
            $runs = $sync->syncAll(force: ! $this->option('due'), trigger: $this->option('due') ? 'schedule' : 'manual');
        }

        if ($runs === []) {
            $this->line('No sources were due.');

            return self::SUCCESS;
        }

        $rows = [];
        $failed = 0;
        foreach ($runs as $run) {
            $run->loadMissing('source');
            $failed += $run->status === 'error' ? 1 : 0;
            $rows[] = [
                $run->source?->name, strtoupper($run->status), $run->fetched, $run->created, $run->updated,
                $run->error ? mb_strimwidth($run->error, 0, 70, '…') : '',
            ];
        }
        $this->table(['Source', 'Result', 'Fetched', 'New', 'Updated', 'Error'], $rows);

        // Exit 1 only if EVERY attempted source failed (partial failures are normal and shown in the UI).
        return $failed === count($runs) ? self::FAILURE : self::SUCCESS;
    }
}
