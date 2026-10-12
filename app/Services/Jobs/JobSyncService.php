<?php

namespace App\Services\Jobs;

use App\Jobs\AnalyzeJobMatch;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\JobSource;
use App\Models\SourceSyncRun;
use App\Services\Ai\Redactor;
use App\Services\Jobs\Connectors\FetchResult;
use App\Services\Jobs\Connectors\HealthResult;
use App\Services\Jobs\Connectors\SourceFetchException;
use App\Services\Matching\IdfIndex;
use App\Services\Matching\MatchService;
use App\Services\Notifications\NotificationService;
use App\Services\SettingsService;
use Illuminate\Support\Carbon;
use Throwable;

class JobSyncService
{
    public function __construct(
        private readonly ConnectorRegistry $connectors,
        private readonly JobUpserter $upserter,
        private readonly MatchService $matches,
        private readonly NotificationService $notifications,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Sync one source. NEVER throws: every outcome is recorded on the source and the run row,
     * so one broken source cannot stop the others.
     */
    public function sync(JobSource $source, string $trigger = 'schedule'): SourceSyncRun
    {
        $run = SourceSyncRun::create([
            'job_source_id' => $source->id,
            'started_at' => now(),
            'status' => 'running',
            'trigger' => $trigger,
        ]);

        if (! $source->type->isSyncable()) {
            return $this->finish($run, 'ok');
        }

        $connector = $this->connectors->forSource($source);

        try {
            $result = $connector->fetchJobs($source);
        } catch (SourceFetchException $e) {
            return $this->fail($source, $run, $e->getMessage(), $e->httpStatus);
        } catch (Throwable $e) {
            report_safely($e, 'sync source #'.$source->id);

            return $this->fail($source, $run, 'Unexpected error while reading this source ('.class_basename($e).').', null);
        }

        $created = $updated = $unchanged = 0;
        $touched = [];
        $failedRecords = 0;

        foreach ($result->jobs as $normalized) {
            try {
                [$job, $outcome] = $this->upserter->upsert($source, $normalized);
                match ($outcome) {
                    JobUpserter::CREATED => $created++,
                    JobUpserter::UPDATED => $updated++,
                    default => $unchanged++,
                };
                if ($outcome !== JobUpserter::UNCHANGED) {
                    $touched[] = $job;
                }
            } catch (Throwable $e) {
                $failedRecords++;
                report_safely($e, 'upsert in source #'.$source->id);
            }
        }

        // Full-listing sources: anything not seen in this complete fetch has been closed.
        $closed = 0;
        if (! $result->notModified && $connector->isFullListing() && $failedRecords === 0) {
            $closed = JobPosting::query()
                ->where('source_id', $source->id)->where('state', 'active')
                ->where('last_seen_at', '<', $run->started_at)
                ->update(['state' => 'expired']);
        }

        $run->fill([
            'fetched' => count($result->jobs),
            'created' => $created,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'http_status' => $result->httpStatus,
        ]);

        $this->markHealthy($source, $connector->minIntervalMinutes(), $result, [
            'fetched' => count($result->jobs), 'created' => $created, 'updated' => $updated, 'closed' => $closed,
            'skipped' => $result->skipped + $failedRecords,
        ]);

        // New postings change which words are distinctive; rebuild the TF-IDF index lazily.
        if ($created > 0) {
            app(IdfIndex::class)->invalidate();
        }

        // Free, rule-based scoring right away; AI analysis is queued separately and rate-limited.
        $scored = $this->matches->scoreMany($touched);
        $this->queueAiAnalysis($scored);

        return $this->finish($run, 'ok');
    }

    /**
     * Sync every enabled source that is due (or all of them when $force). Failures are isolated.
     *
     * @return list<SourceSyncRun>
     */
    public function syncAll(bool $force = false, string $trigger = 'schedule'): array
    {
        $runs = [];
        JobSource::query()->where('enabled', true)->get()
            ->filter(fn (JobSource $s) => $s->type->isSyncable() && ($force || $s->isDue()))
            ->each(function (JobSource $s) use (&$runs, $trigger) {
                $runs[] = $this->sync($s, $trigger);
            });

        return $runs;
    }

    public function test(JobSource $source): HealthResult
    {
        $result = $this->connectors->forSource($source)->healthCheck($source);
        $source->forceFill([
            'health_status' => $result->status,
            'last_error' => $result->ok ? null : Redactor::scrubSecrets($result->message),
        ])->save();

        return $result;
    }

    /** @param list<JobMatch> $scored */
    public function queueAiAnalysis(array $scored): int
    {
        $pending = collect($scored)->filter(fn ($m) => $m->ai_status === 'pending')
            ->sortByDesc('score')->take((int) config('jobpilot.ai_jobs_per_scan', 8))->values();

        $spacing = (int) config('jobpilot.ai_job_spacing_seconds', 8);
        foreach ($pending as $i => $m) {
            AnalyzeJobMatch::dispatch($m->id)->delay(now()->addSeconds($i * $spacing));
        }

        return $pending->count();
    }

    private function markHealthy(JobSource $source, int $connectorMin, FetchResult $result, array $stats): void
    {
        $interval = max(
            (int) $this->settings->get('scan_frequency_minutes', 60),
            $connectorMin,
            (int) ($result->meta['feed_ttl_minutes'] ?? 0),
        );

        $prev = (array) $source->last_stats;
        $source->forceFill([
            'health_status' => 'ok',
            'last_error' => null,
            'consecutive_failures' => 0,
            'last_synced_at' => now(),
            'next_sync_at' => now()->addMinutes($interval),
            'last_stats' => array_merge($prev, $stats, array_filter([
                'http_cache' => $result->meta['http_cache'] ?? ($prev['http_cache'] ?? null),
                'requests_today' => isset($result->meta['requests'])
                    ? ((($prev['requests_day'] ?? null) === now()->toDateString() ? ($prev['requests_today'] ?? 0) : 0) + $result->meta['requests'])
                    : null,
                'requests_day' => isset($result->meta['requests']) ? now()->toDateString() : null,
            ])),
        ])->save();
    }

    private function fail(JobSource $source, SourceSyncRun $run, string $message, ?int $http): SourceSyncRun
    {
        $message = Redactor::scrubSecrets($message);
        $failures = $source->consecutive_failures + 1;
        $unconfigured = str_contains($message, 'not configured');

        $source->forceFill([
            'health_status' => $unconfigured ? 'unconfigured' : 'error',
            'last_error' => $message,
            'consecutive_failures' => $failures,
            // Back off exponentially (15 min .. 12 h) instead of hammering a failing source.
            'next_sync_at' => now()->addMinutes(min(720, 15 * (2 ** min($failures - 1, 6)))),
        ])->save();

        if ($failures >= 3 && ! $unconfigured) {
            $this->notifications->sourceFailing($source);
        }

        $run->fill(['http_status' => $http, 'error' => $message]);

        return $this->finish($run, 'error');
    }

    private function finish(SourceSyncRun $run, string $status): SourceSyncRun
    {
        $run->fill(['status' => $status, 'finished_at' => now()])->save();

        return $run;
    }

    public function expireStale(): int
    {
        $cutoff = Carbon::now()->subDays((int) config('jobpilot.job_expiry_days', 30));

        return JobPosting::query()->where('state', 'active')->where('is_manual', false)
            ->where(function ($q) use ($cutoff) {
                $q->where('last_seen_at', '<', $cutoff)->orWhere('expires_at', '<', now());
            })->update(['state' => 'expired']);
    }
}
