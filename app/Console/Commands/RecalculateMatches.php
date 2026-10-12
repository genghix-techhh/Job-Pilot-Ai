<?php

namespace App\Console\Commands;

use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Services\Jobs\JobSyncService;
use App\Services\Matching\MatchService;
use Illuminate\Console\Command;

class RecalculateMatches extends Command
{
    protected $signature = 'app:recalculate-matches
        {--force : Re-score every active job even if its score is up to date}
        {--pending-ai : Only queue the Gemini analysis for matches still waiting for it}';

    protected $description = 'Re-score jobs against your reviewed profile. Cache-aware; spends no AI calls unless --pending-ai';

    public function handle(MatchService $matches, JobSyncService $sync): int
    {
        $profile = $matches->activeProfile();
        if (! $profile) {
            $this->warn('No reviewed profile yet — upload your CV and save your profile first.');

            return self::SUCCESS;
        }

        if ($this->option('pending-ai')) {
            $pending = JobMatch::query()->with('job')->where('profile_version', $profile->profile_version)
                ->whereIn('ai_status', ['pending', 'none'])->whereHas('job', fn ($q) => $q->where('state', 'active'))
                ->orderByDesc('score')->limit(60)->get()
                ->filter(fn ($m) => $m->score >= \settings()->aiPrefilterThreshold());
            foreach ($pending as $m) {
                $m->ai_status === 'none' ? $m->update(['ai_status' => 'pending']) : null;
            }
            $n = $sync->queueAiAnalysis($pending->values()->all());
            $this->info("Queued {$n} AI analysis job(s) (bounded by AI_JOBS_PER_SCAN and the daily budget).");

            return self::SUCCESS;
        }

        $hash = \settings()->scoringHash();
        $done = 0;
        JobPosting::query()->where('state', 'active')->whereNull('duplicate_of')->orderByDesc('id')->chunk(200, function ($chunk) use ($matches, $profile, $hash, &$done) {
            $todo = $this->option('force') ? $chunk : $chunk->filter(function ($job) use ($profile, $hash) {
                $m = JobMatch::query()->where(['job_id' => $job->id, 'profile_version' => $profile->profile_version, 'job_checksum' => $job->description_checksum])->first();

                return ! $m || $m->scoring_hash !== $hash;
            });
            $done += count($matches->scoreMany($todo, notify: false));
        });
        $this->info("Scored {$done} job(s).");

        return self::SUCCESS;
    }
}
