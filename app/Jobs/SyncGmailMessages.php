<?php

namespace App\Jobs;

use App\Models\ConnectedAccount;
use App\Services\Mail\GmailSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class SyncGmailMessages implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('sync-gmail'))->releaseAfter(60)->expireAfter(600)];
    }

    public function handle(GmailSyncService $sync): void
    {
        ConnectedAccount::query()->where('provider', 'google')->where('status', 'connected')->get()
            ->each(fn (ConnectedAccount $a) => $sync->sync($a));
    }
}
