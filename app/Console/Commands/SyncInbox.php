<?php

namespace App\Console\Commands;

use App\Models\ConnectedAccount;
use App\Services\Mail\GmailClient;
use App\Services\Mail\GmailSyncService;
use Illuminate\Console\Command;

class SyncInbox extends Command
{
    protected $signature = 'app:sync-inbox';

    protected $description = 'Check the connected Gmail inbox (read-only) for recruiter replies, interviews and assessments';

    public function handle(GmailSyncService $sync, GmailClient $gmail): int
    {
        $accounts = ConnectedAccount::query()->where('provider', 'google')->where('status', 'connected')->get();

        if ($accounts->isEmpty()) {
            $this->line('Gmail is not connected — nothing to do.');

            return self::SUCCESS;
        }
        if (! $gmail->isConfigured()) {
            $this->error('Google OAuth credentials are not configured (GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET).');

            return self::FAILURE;
        }

        $failed = false;
        foreach ($accounts as $account) {
            $r = $sync->sync($account);
            $failed = $failed || $r['error'] !== null;
            $this->line(sprintf('%s: checked %d, stored %d, needs review %d%s', $account->email, $r['checked'], $r['stored'], $r['needs_review'], $r['error'] ? " — ERROR: {$r['error']}" : ''));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
