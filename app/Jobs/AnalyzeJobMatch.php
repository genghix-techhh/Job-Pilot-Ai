<?php

namespace App\Jobs;

use App\Models\JobMatch;
use App\Services\Ai\AiException;
use App\Services\Matching\MatchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Runs the Gemini analysis for one match row. Safe to repeat: results are cached on the job
 * version / match row, so an already analysed match makes no API call. If the free quota is
 * exhausted or Gemini is rate-limiting, the job is pushed back until the reported retry time
 * and the match stays "pending" (visible in the UI) — it never retries aggressively.
 */
class AnalyzeJobMatch implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $tries = 8;

    public int $timeout = 120;

    public function __construct(public int $matchId) {}

    public function handle(MatchService $matches): void
    {
        $match = JobMatch::with('job')->find($this->matchId);
        if (! $match || ! $match->job || $match->ai_status === 'done' || $match->ai_status === 'skipped') {
            return;
        }

        try {
            $matches->analyzeWithAi($match->job, $match);
        } catch (AiException $e) {
            if ($this->job && $e->isTemporary()) {
                $seconds = $e->retryAt ? max(60, min(3600, now()->diffInSeconds($e->retryAt, false))) : 120;
                $this->release((int) $seconds);

                return;
            }
            // Non-queue context (sync driver) or non-temporary: the match row already holds the status.
        }
    }
}
