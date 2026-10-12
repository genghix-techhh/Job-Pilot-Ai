<?php

namespace App\Services\Matching;

use App\Enums\EligibilityStatus;
use App\Enums\JobKind;
use App\Enums\MatchTier;
use App\Models\CandidateProfile;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Services\Ai\AiException;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiResultSanitizer;
use App\Services\Ai\Prompts\JobRequirementsPrompt;
use App\Services\Ai\Prompts\MatchAssessmentPrompt;
use App\Services\Ai\Redactor;
use App\Services\Notifications\NotificationService;
use App\Services\Profile\AiProfilePayload;
use App\Services\SettingsService;
use Illuminate\Support\Str;

/**
 * Scores jobs against the reviewed profile and manages the (optional) AI analysis.
 *
 * - Rule-based scoring is free, instant and always runs.
 * - Gemini is only consulted for jobs that pass the rule-based pre-filter, never twice for the
 *   same job version / profile version, and never when consent, budget or key are missing.
 */
class MatchService
{
    private ?ProfileSnapshot $snapshot = null;

    public function __construct(
        private readonly MatchScorer $scorer,
        private readonly FreelanceFitAnalyzer $freelance,
        private readonly SettingsService $settings,
        private readonly AiProvider $ai,
        private readonly AiProfilePayload $payloads,
        private readonly AiResultSanitizer $sanitizer,
        private readonly Redactor $redactor,
        private readonly NotificationService $notifications,
    ) {}

    /** The profile used for matching: it must exist and have been reviewed/saved by the owner. */
    public function activeProfile(): ?CandidateProfile
    {
        $p = CandidateProfile::query()->with('user')->first();

        return $p && $p->profile_version >= 1 && $p->hasContent() ? $p : null;
    }

    public function snapshot(CandidateProfile $p): ProfileSnapshot
    {
        if ($this->snapshot === null || $this->snapshot->version !== (int) $p->profile_version) {
            $this->snapshot = ProfileSnapshot::from($p, $this->settings);
        }

        return $this->snapshot;
    }

    /** Drop memoised state (after settings/profile changes inside one process). */
    public function reset(): void
    {
        $this->snapshot = null;
    }

    public function scoreJob(JobPosting $job, ?CandidateProfile $profile = null): ?JobMatch
    {
        $profile ??= $this->activeProfile();
        if (! $profile) {
            return null;
        }
        $snap = $this->snapshot($profile);

        $key = [
            'user_id' => $profile->user_id,
            'job_id' => $job->id,
            'profile_version' => $profile->profile_version,
            'job_checksum' => $job->description_checksum ?: hash('sha256', (string) $job->id),
        ];
        $existing = JobMatch::query()->where($key)->first();

        $requirements = $job->ai_requirements_checksum && $job->ai_requirements_checksum === $job->description_checksum
            ? $job->ai_requirements : null;
        $assessment = $existing?->ai_analysis;

        $r = $this->scorer->score($job, $snap, $requirements, $assessment);

        [$aiStatus, $aiNote] = $this->aiState($existing, $r, $job);

        return JobMatch::updateOrCreate($key, [
            'model_id' => $assessment || $requirements ? config('jobpilot.gemini.model') : null,
            'score' => $r['score'],
            'tier' => $r['tier'],
            'scoring_hash' => $this->settings->scoringHash(),
            'factors' => $r['factors'],
            'matched_skills' => $r['matched_skills'],
            'missing_skills' => $r['missing_skills'],
            'eligibility_status' => $r['eligibility_status'],
            'eligibility_note' => Str::limit($r['eligibility_note'], 250, ''),
            'rationale' => ! empty($assessment['summary']) ? trim($assessment['summary'].' '.$r['rationale']) : $r['rationale'],
            'exclusion' => $r['exclusion'],
            'ai_status' => $aiStatus,
            'ai_note' => $aiNote,
            'freelance_fit' => $job->kind === JobKind::Freelance ? $this->freelance->analyze($job, $snap) : null,
            'cached_at' => now(),
        ]);
    }

    /**
     * @param  iterable<JobPosting>  $jobs
     * @return list<JobMatch> matches that were newly scored
     */
    public function scoreMany(iterable $jobs, bool $notify = true): array
    {
        $profile = $this->activeProfile();
        if (! $profile) {
            return [];
        }

        $out = [];
        $notified = 0;
        foreach ($jobs as $job) {
            $match = $this->scoreJob($job, $profile);
            if (! $match) {
                continue;
            }
            $out[] = $match;

            $fresh = $job->first_seen_at && $job->first_seen_at->gt(now()->subDays(3));
            if ($notify && $fresh && $notified < 5 && $match->tier === MatchTier::Strong && $match->exclusion === null
                && $match->eligibility_status !== EligibilityStatus::Restricted && $job->user_status !== 'hidden') {
                if ($this->notifications->strongMatch($job, $match)) {
                    $notified++;
                }
            }
        }

        return $out;
    }

    /** Should this job be sent to Gemini at all? (free, rule-based gate) */
    public function worthAi(array $scoreResult): bool
    {
        return ($scoreResult['exclusion'] ?? null) === null          // never spend an AI call on a job you excluded
            && $scoreResult['score'] >= $this->settings->aiPrefilterThreshold()
            && ($scoreResult['role_match'] || $scoreResult['skill_overlap'] >= 2);
    }

    /** @return array{0: string, 1: ?string} */
    private function aiState(?JobMatch $existing, array $r, JobPosting $job): array
    {
        if ($existing?->ai_status === 'done' && $existing->ai_analysis) {
            return ['done', $existing->ai_note];
        }
        if ($existing?->ai_status === 'failed') {
            return ['failed', $existing->ai_note];
        }
        if (! empty($r['exclusion'])) {
            return ['skipped', 'Excluded by your rules ('.$r['exclusion'].'), so no AI call was spent.'];
        }
        if (mb_strlen((string) $job->description) < 120) {
            return ['skipped', 'Too little posting text for AI analysis.'];
        }
        if (! $this->worthAi($r)) {
            return ['skipped', 'Rule-based score is below the AI pre-filter, so no AI call was spent.'];
        }
        $avail = $this->ai->availability();
        if (! $avail->available) {
            return in_array($avail->state, ['budget_exhausted', 'paused'], true)
                ? ['pending', $avail->message]
                : ['none', $avail->message];
        }

        return ['pending', 'Waiting for AI analysis.'];
    }

    /**
     * Run the (up to) two narrow Gemini tasks for one job and re-score with the validated result.
     *
     * @throws AiException temporary failures bubble up so the queue can retry later
     */
    public function analyzeWithAi(JobPosting $job, JobMatch $match): JobMatch
    {
        $profile = $this->activeProfile();
        if (! $profile || $match->profile_version !== (int) $profile->profile_version) {
            return $match; // stale: a newer profile version has its own match row
        }

        try {
            $this->ensureRequirements($job);
            $assessment = $this->ensureAssessment($job->refresh(), $match, $profile);
        } catch (AiException $e) {
            if ($e->isTemporary()) {
                $match->update(['ai_status' => 'pending', 'ai_note' => Str::limit($e->getMessage(), 240, '')]);
                throw $e;
            }
            $match->update([
                'ai_status' => $e->isUnavailable() ? 'none' : 'failed',
                'ai_note' => Str::limit($e->getMessage(), 240, ''),
            ]);

            return $match->refresh();
        }

        $match->update(['ai_analysis' => $assessment, 'ai_status' => 'done', 'ai_note' => null, 'model_id' => config('jobpilot.gemini.model')]);

        return $this->scoreJob($job->refresh(), $profile) ?? $match;
    }

    /** @throws AiException */
    private function ensureRequirements(JobPosting $job): void
    {
        if ($job->ai_requirements_checksum === $job->description_checksum && $job->ai_requirements) {
            return; // cached for this exact job version
        }
        $description = $this->redactor->redactGeneral((string) $job->description);
        $raw = $this->ai->generateJson(JobRequirementsPrompt::build(
            $this->redactor->redactGeneral($job->title),
            $description,
            $this->redactor->redactGeneral((string) $job->location),
            $job->id,
        ));
        $job->forceFill([
            'ai_requirements' => $this->sanitizer->requirements($raw),
            'ai_requirements_checksum' => $job->description_checksum,
        ])->save();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws AiException
     */
    private function ensureAssessment(JobPosting $job, JobMatch $match, CandidateProfile $profile): array
    {
        if ($match->ai_analysis) {
            return $match->ai_analysis;
        }
        $payload = $this->payloads->build($profile);
        $req = $job->ai_requirements ?? [];
        $summary = [
            'title' => $this->redactor->redactGeneral($job->title),
            'seniority' => $req['seniority'] ?? 'unknown',
            'required_skills' => $req['required_skills'] ?? ($job->skills ?? []),
            'responsibilities' => $req['responsibilities'] ?? [],
        ];
        $raw = $this->ai->generateJson(MatchAssessmentPrompt::build($payload, $summary, $job->id));

        return $this->sanitizer->assessment($raw, $this->payloads->evidenceIndex($payload));
    }
}
