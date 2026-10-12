<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduler — needs ONE cron entry:  * * * * * php /path/to/artisan schedule:run
|--------------------------------------------------------------------------
| Everything here is also available as a manual button in the dashboard, so the app
| remains usable (just not automatic) if cron cannot be used.
*/

// Heartbeat so the Activity page can tell whether cron is really running the scheduler.
Schedule::call(fn () => Cache::put('scheduler.heartbeat', now()->toIso8601String(), now()->addDay()))->everyMinute()->name('heartbeat');

// Sources are only fetched when due (each source has its own next_sync_at, and its connector's
// minimum interval is always respected), so a short tick here never hammers a provider.
Schedule::command('app:sync-job-sources --due')->everyTenMinutes()->withoutOverlapping(30)->runInBackground();

// Gmail (no-op unless the owner connected an account).
Schedule::command('app:sync-inbox')->cron('*/'.max(5, (int) config('jobpilot.inbox_sync_interval_minutes', 30)).' * * * *')->withoutOverlapping(30)->runInBackground();

// Retry AI analyses that were postponed by the free-tier quota or the daily budget.
Schedule::command('app:recalculate-matches --pending-ai')->everyThirtyMinutes()->withoutOverlapping(30)->runInBackground();

// Interview/assessment reminders + bounded retry of failed e-mail alerts.
Schedule::command('app:send-reminders')->everyFiveMinutes()->withoutOverlapping(10);

// Digest of new matches: checked hourly, sent once per day/week at the hour chosen in Settings.
Schedule::command('app:send-digest')->hourly()->withoutOverlapping(30);

Schedule::command('app:expire-jobs')->dailyAt('03:30');

// For hosts without a permanently running worker: drain the database queue every minute.
// (If you run a supervised `queue:work`, set SCHEDULER_RUNS_QUEUE=false.)
if (config('jobpilot.scheduler_runs_queue') && config('queue.default') === 'database') {
    Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=3')->everyMinute()->withoutOverlapping(2)->runInBackground();
}
