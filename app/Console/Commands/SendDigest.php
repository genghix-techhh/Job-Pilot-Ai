<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Notifications\DigestService;
use Illuminate\Console\Command;

class SendDigest extends Command
{
    protected $signature = 'app:send-digest {--force : Send now, ignoring the schedule and the "already sent" check}';

    protected $description = 'E-mail the best new job matches since the last digest (respects your digest settings)';

    public function handle(DigestService $digest): int
    {
        $user = User::query()->first();
        if (! $user) {
            $this->line('No owner yet.');

            return self::SUCCESS;
        }

        $log = $digest->send($user, (bool) $this->option('force'));
        $this->info($log
            ? "Digest {$log->status} to {$log->recipient}."
            : 'Nothing to send (not due, digest is off, or no new matches).');

        return self::SUCCESS;
    }
}
