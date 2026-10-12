<?php

namespace App\Services\Ai;

use App\Models\AiUsageLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Local guard rails around the free tier: a configurable daily call budget
 * (counted per Google quota day) and a pause switch that is set when Gemini
 * answers 429. It never routes around limits (no extra keys, no paid fallback).
 */
class AiBudget
{
    private const PAUSE_KEY = 'ai.gemini.paused';

    /** Calls that actually reached Google (guard-blocked attempts are not counted). */
    private const COUNTED = ['ok', 'error', 'rate_limited', 'invalid'];

    public function limit(): int
    {
        $override = settings()->get('gemini_daily_limit');

        return max(0, (int) ($override ?? config('jobpilot.gemini.daily_call_limit', 50)));
    }

    /** Start of the current quota day (Google resets at midnight Pacific time), in UTC. */
    public function windowStart(): Carbon
    {
        $tz = config('jobpilot.gemini.quota_reset_timezone', 'America/Los_Angeles');

        return Carbon::now($tz)->startOfDay()->utc();
    }

    public function nextReset(): Carbon
    {
        return $this->windowStart()->copy()->addDay();
    }

    public function callsToday(): int
    {
        return AiUsageLog::query()
            ->where('created_at', '>=', $this->windowStart())
            ->whereIn('status', self::COUNTED)
            ->count();
    }

    public function remaining(): int
    {
        return max(0, $this->limit() - $this->callsToday());
    }

    public function exhausted(): bool
    {
        return $this->remaining() <= 0;
    }

    public function pausedUntil(): ?Carbon
    {
        $until = Cache::get(self::PAUSE_KEY);
        if (! $until) {
            return null;
        }
        $until = Carbon::parse($until);

        return $until->isFuture() ? $until : null;
    }

    public function pauseReason(): ?string
    {
        return Cache::get(self::PAUSE_KEY.'.reason');
    }

    public function pause(Carbon $until, string $reason): void
    {
        Cache::put(self::PAUSE_KEY, $until->toIso8601String(), $until->copy()->addMinutes(5));
        Cache::put(self::PAUSE_KEY.'.reason', $reason, $until->copy()->addMinutes(5));
    }

    public function clearPause(): void
    {
        Cache::forget(self::PAUSE_KEY);
        Cache::forget(self::PAUSE_KEY.'.reason');
    }
}
