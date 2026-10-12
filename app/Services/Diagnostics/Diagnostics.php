<?php

namespace App\Services\Diagnostics;

use App\Models\AiUsageLog;
use App\Services\Ai\AiBudget;
use App\Services\Ai\AiProvider;
use App\Services\Mail\GmailClient;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only health information. Never returns secrets — only whether they are present. */
class Diagnostics
{
    public function __construct(
        private readonly AiProvider $ai,
        private readonly AiBudget $budget,
        private readonly NotificationService $notifications,
        private readonly GmailClient $gmail,
    ) {}

    /** @return array<string, mixed> */
    public function ai(): array
    {
        $avail = $this->ai->availability();
        $model = (string) config('jobpilot.gemini.model');
        $recentErrors = AiUsageLog::query()->whereNotIn('status', ['ok'])->latest('id')->limit(5)->get();

        return [
            'available' => $avail->available,
            'state' => $avail->state,
            'message' => $avail->message,
            'retry_at' => $avail->retryAt,
            'model' => $model,
            'model_warning' => in_array($model, config('jobpilot.gemini.known_free_models', []), true)
                ? null
                : "\"{$model}\" is not on the list of models documented with a free tier when this app was built. Confirm its free availability in Google AI Studio before relying on it.",
            'enabled' => (bool) config('jobpilot.gemini.enabled'),
            'key_present' => filled(config('jobpilot.gemini.api_key')),
            'calls_today' => $this->budget->callsToday(),
            'limit' => $this->budget->limit(),
            'remaining' => $this->budget->remaining(),
            'resets_at' => $this->budget->nextReset(),
            'paused_until' => $this->budget->pausedUntil(),
            'pause_reason' => $this->budget->pauseReason(),
            'recent_errors' => $recentErrors,
        ];
    }

    /** @return array<string, mixed> */
    public function queue(): array
    {
        $driver = (string) config('queue.default');
        $pending = 0;
        $oldestMinutes = null;
        $failed = 0;

        try {
            if ($driver === 'database' && Schema::hasTable('jobs')) {
                $pending = (int) DB::table('jobs')->count();
                $oldest = DB::table('jobs')->min('created_at');
                $oldestMinutes = $oldest ? (int) floor((time() - (int) $oldest) / 60) : null;
            }
            if (Schema::hasTable('failed_jobs')) {
                $failed = (int) DB::table('failed_jobs')->count();
            }
        } catch (\Throwable) {
            // diagnostics must never crash a page
        }

        return [
            'driver' => $driver,
            'pending' => $pending,
            'oldest_minutes' => $oldestMinutes,
            'failed' => $failed,
            // Jobs waiting for many minutes means nothing is processing the queue.
            'stalled' => $driver === 'database' && $pending > 0 && ($oldestMinutes ?? 0) >= 5,
        ];
    }

    /** @return array<string, mixed> */
    public function scheduler(): array
    {
        $beat = Cache::get('scheduler.heartbeat');
        $last = $beat ? Carbon::parse($beat) : null;

        return [
            'last_beat' => $last,
            'running' => $last !== null && $last->gt(now()->subMinutes(3)),
        ];
    }

    /** @return array<string, mixed> */
    public function system(): array
    {
        $db = 'unknown';
        try {
            DB::connection()->getPdo();
            $db = DB::connection()->getDriverName();
        } catch (\Throwable) {
            $db = 'unreachable';
        }

        $writable = fn (string $p) => is_dir($p) && is_writable($p);

        return [
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'env' => app()->environment(),
            'debug' => (bool) config('app.debug'),
            'url' => config('app.url'),
            'https' => str_starts_with((string) config('app.url'), 'https://'),
            'database' => $db,
            'timezone' => config('app.timezone'),
            'storage_writable' => $writable(storage_path()),
            'cache_writable' => $writable(base_path('bootstrap/cache')),
            'mail' => $this->notifications->transportStatus(),
            'google_configured' => $this->gmail->isConfigured(),
            'free_disk_gb' => round(@disk_free_space(storage_path()) / 1073741824, 1),
        ];
    }
}
