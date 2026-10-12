<?php

use App\Services\Ai\Redactor;
use App\Services\SettingsService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

if (! function_exists('settings')) {
    function settings(): SettingsService
    {
        return app(SettingsService::class);
    }
}

if (! function_exists('report_safely')) {
    /**
     * Log a failure without ever writing request bodies, CV text or secrets:
     * only the exception class, a scrubbed message and the code location.
     */
    function report_safely(Throwable $e, string $context = ''): void
    {
        Log::warning(trim($context.' '.class_basename($e).': '.Redactor::scrubSecrets($e->getMessage())), [
            'at' => basename($e->getFile()).':'.$e->getLine(),
        ]);
    }
}

if (! function_exists('user_tz')) {
    /** The owner's display time zone (datetimes are stored in UTC). */
    function user_tz(): string
    {
        $user = auth()->user();

        return $user?->tz() ?? config('jobpilot.timezone', 'UTC');
    }
}

if (! function_exists('jp_dt')) {
    /** Format a stored (UTC) datetime in the owner's time zone. */
    function jp_dt(CarbonInterface|string|null $date, string $format = 'M j, Y g:i A', ?string $tz = null): string
    {
        if ($date === null || $date === '') {
            return '—';
        }
        $c = $date instanceof CarbonInterface ? Carbon::instance($date) : Carbon::parse($date, 'UTC');

        return $c->copy()->setTimezone($tz ?? user_tz())->format($format);
    }
}

if (! function_exists('jp_date')) {
    function jp_date(CarbonInterface|string|null $date): string
    {
        return jp_dt($date, 'M j, Y');
    }
}

if (! function_exists('jp_ago')) {
    function jp_ago(CarbonInterface|string|null $date): string
    {
        if (! $date) {
            return 'never';
        }
        $c = $date instanceof CarbonInterface ? $date : Carbon::parse($date, 'UTC');

        return $c->diffForHumans();
    }
}
