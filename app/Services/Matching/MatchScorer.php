<?php

namespace App\Services\Matching;

use App\Enums\MatchTier;
use App\Models\JobPosting;
use App\Services\SettingsService;
use Illuminate\Support\Str;

/**
 * Transparent 100-point score.
 *
 * Factors (default weights): skills 30, duties 25, seniority 15, projects 15, salary 10,
 * freshness 5. Weights are owner-editable. A factor that cannot be assessed for a job
 * (e.g. no salary preference, or the job states no salary) is marked not applicable and
 * its weight is redistributed proportionally over the factors that can be assessed —
 * it is never silently scored as zero.
 *
 * Remote eligibility is NOT part of the score; see EligibilityResolver.
 */
class MatchScorer
{
    public const LABELS = [
        'skills' => 'Relevant technical skills',
        'duties' => 'Duties & responsibilities',
        'seniority' => 'Experience & seniority fit',
        'projects' => 'Relevant project evidence',
        'salary' => 'Salary preference',
        'freshness' => 'Freshness',
    ];

    /**
     * TF-IDF cosine between a posting and a CV at which the wording counts as a (near) full match.
     * Calibrated on ~350 real postings (Remote OK, Greenhouse, Lever): median similarity is ~0.008, the
     * 95th percentile ~0.03 and the very best postings ~0.09. A square-root curve spreads that heavy tail
     * so ordinary jobs are distinguishable while only genuinely close ones approach 100%.
     */
    private const SIM_REFERENCE = 0.10;

    private const LEVEL_WORDS = ['senior', 'sr', 'junior', 'jr', 'staff', 'principal', 'lead', 'associate', 'entry', 'level', 'remote', 'mid', 'intermediate'];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly EligibilityResolver $eligibility,
        private readonly IdfIndex $idf,
        private readonly ExclusionRules $exclusions,
    ) {}

    /**
     * @param  array<string, mixed>|null  $requirements  validated AI requirement extraction (cached on the job)
     * @param  array<string, mixed>|null  $assessment  validated AI match assessment (profile-specific)
     * @return array<string, mixed>
     */
    public function score(JobPosting $job, ProfileSnapshot $p, ?array $requirements = null, ?array $assessment = null): array
    {
        $weights = $this->settings->weights();

        // ---- skills ------------------------------------------------------------------
        // Skills in the title count double, "required" skills once, "nice to have" skills half.
        [$required, $preferred, $titleSkills] = $this->jobSkills($job, $requirements);
        $jobSkills = array_values(array_unique(array_merge($required, $preferred)));
        $weightOf = fn (string $s) => in_array($s, $titleSkills, true) ? 2.0 : (in_array($s, $required, true) ? 1.0 : 0.5);
        $matched = array_values(array_filter($jobSkills, fn ($s) => $p->hasSkill($s)));
        $missing = array_values(array_filter($required, fn ($s) => ! $p->hasSkill($s)));
        if ($jobSkills === []) {
            $skills = $this->na('No recognisable technologies were found in this posting.');
        } else {
            $total = array_sum(array_map($weightOf, $jobSkills));
            $got = array_sum(array_map($weightOf, $matched));
            $skills = $this->factor(
                min(1, $got / max(3.0, min($total, 8.0))),
                'Matches '.count($matched).' of '.count($jobSkills).' skills named'.($matched ? ': '.implode(', ', array_slice($matched, 0, 8)) : '')
                .'. Title skills count double, nice-to-haves half.'
            );
        }

        // ---- duties ------------------------------------------------------------------
        $titleRoles = SkillCatalog::rolesIn($job->title);
        $roleMatch = array_values(array_intersect($titleRoles, $p->roles));
        if (! $p->hasExperienceText()) {
            $duties = $this->na('Add work history to your profile so duties can be compared.');
        } else {
            $text = $job->title."\n".$job->description;
            if ($this->idf->usable()) {
                $sim = TextOverlap::tfidfSimilarity($text, $p->corpus, $this->idf);
                $textPct = min(1.0, sqrt($sim / self::SIM_REFERENCE));
                $how = sprintf('Wording similarity with your experience is %d%% of a strong match (TF-IDF: distinctive terms count, boilerplate does not)', round($textPct * 100));
            } else {
                $cov = TextOverlap::coverage($text, $p->corpus)['coverage'];
                $textPct = min(1, $cov / 0.55);
                $how = sprintf('%d%% of the posting\'s key terms appear in your experience (too few collected jobs yet for TF-IDF)', round($cov * 100));
            }
            $titleSim = $this->titleSimilarity($job->title, $p->titles);
            $roleSignal = max($roleMatch ? 1.0 : 0.0, min(1.0, $titleSim * 1.6));
            $heur = 0.55 * $textPct + 0.45 * $roleSignal;
            $pct = $heur;
            $detail = $how.($roleMatch ? '; title matches your target role "'.$roleMatch[0].'"' : '')
                .($titleSim > 0 ? sprintf('; title resembles your past roles (%d%%)', round($titleSim * 100)) : '').'.';
            if (isset($assessment['duties_fit'])) {
                $pct = 0.5 * $heur + 0.5 * ($assessment['duties_fit'] / 100);
                $detail .= ' AI assessment of duties fit: '.$assessment['duties_fit'].'/100 (blended 50/50 with the text check).';
            }
            $duties = $this->factor($pct, $detail);
        }

        // ---- seniority ---------------------------------------------------------------
        $seniority = $this->seniority($job, $p, $requirements);

        // ---- projects ----------------------------------------------------------------
        if ($p->projectCount === 0) {
            $projects = $this->factor(0.0, 'No projects on your profile yet — add some to earn credit here.');
        } else {
            $inProjects = array_values(array_filter($jobSkills, fn ($s) => isset($p->projectSkills[Str::lower($s)])));
            $heur = $jobSkills === [] ? min(1, TextOverlap::coverage($job->description, $p->projectText)['coverage'] / 0.4)
                : min(1, count($inProjects) / max(2, min(count($jobSkills), 5)));
            $pct = $heur;
            $detail = $jobSkills === []
                ? 'Compared the posting\'s wording with your project descriptions.'
                : count($inProjects).' of the posting\'s skills appear in your projects'.($inProjects ? ': '.implode(', ', array_slice($inProjects, 0, 6)) : '').'.';
            if (isset($assessment['project_fit'])) {
                $pct = 0.5 * $heur + 0.5 * ($assessment['project_fit'] / 100);
                $detail .= ' AI project-fit: '.$assessment['project_fit'].'/100 (blended 50/50).';
            }
            $projects = $this->factor($pct, $detail);
        }

        // ---- salary ------------------------------------------------------------------
        $salary = $this->salary($job, $p);

        // ---- freshness ---------------------------------------------------------------
        $days = max(0, (int) $job->freshnessDate()->diffInDays(now(), false));
        $fresh = match (true) {
            $days <= 3 => 1.0, $days <= 7 => 0.8, $days <= 14 => 0.6, $days <= 30 => 0.35, $days <= 60 => 0.15, default => 0.0,
        };
        $freshness = $this->factor($fresh, $days === 0 ? 'Posted today.' : "Posted/updated about {$days} day(s) ago.");

        $factors = compact('skills', 'duties', 'seniority', 'projects', 'salary', 'freshness');

        // ---- combine with redistribution -----------------------------------------------
        $activeWeight = 0.0;
        foreach ($factors as $key => $f) {
            if ($f['applicable']) {
                $activeWeight += $weights[$key];
            }
        }
        $score = 0.0;
        foreach ($factors as $key => &$f) {
            $f['label'] = self::LABELS[$key];
            $f['weight'] = round($weights[$key], 1);
            $f['max'] = $f['applicable'] && $activeWeight > 0 ? round($weights[$key] / $activeWeight * 100, 1) : 0.0;
            $f['points'] = $f['applicable'] ? round($f['pct'] * $f['max'], 1) : 0.0;
            $score += $f['points'];
        }
        unset($f);
        $score = round(min(100, max(0, $score)), 1);

        $eligibility = $this->eligibility->resolve($job, $p->homeCountry, $requirements);

        return [
            'score' => $score,
            'tier' => $this->tier($score),
            'factors' => $factors,
            'matched_skills' => $matched,
            'missing_skills' => $missing,
            'eligibility_status' => $eligibility['status'],
            'eligibility_note' => $eligibility['note'],
            'rationale' => $this->rationale($factors, $matched, $missing, $roleMatch),
            'role_match' => $roleMatch !== [],
            'skill_overlap' => count($matched),
            'exclusion' => $this->exclusions->check($job, $p),
        ];
    }

    /** Best Jaccard similarity between the job title and any of the candidate's past titles (level words ignored). */
    private function titleSimilarity(string $jobTitle, array $candidateTitles): float
    {
        $strip = fn (string $t) => array_values(array_diff(array_unique(TextOverlap::tokens(Str::lower($t))), self::LEVEL_WORDS));
        $a = $strip($jobTitle);
        if ($a === []) {
            return 0.0;
        }
        $best = 0.0;
        foreach ($candidateTitles as $t) {
            $b = $strip((string) $t);
            $union = count(array_unique(array_merge($a, $b)));
            if ($union > 0) {
                $best = max($best, count(array_intersect($a, $b)) / $union);
            }
        }

        return $best;
    }

    public function tier(float $score): MatchTier
    {
        return match (true) {
            $score >= $this->settings->strongThreshold() => MatchTier::Strong,
            $score >= $this->settings->potentialThreshold() => MatchTier::Potential,
            default => MatchTier::Low,
        };
    }

    /**
     * @return array{0: list<string>, 1: list<string>, 2: list<string>} [required, preferred, title] canonical skill names
     */
    private function jobSkills(JobPosting $job, ?array $req): array
    {
        // Local split first (works without AI): "Nice to have" sections become preferred.
        $local = RequirementSplitter::split($job->title, (string) $job->description);
        if (! $req) {
            return [$local['required'], $local['preferred'], $local['title']];
        }
        $canon = fn ($s) => SkillCatalog::canonical((string) $s);
        $required = array_map($canon, (array) ($req['required_skills'] ?? []));
        $preferred = array_map($canon, (array) ($req['preferred_skills'] ?? []));
        // Skills our own scan found but the model did not call out count as required unless the posting marks them as bonus.
        $required = array_values(array_unique(array_merge($required, array_diff($local['required'], $preferred))));
        $preferred = array_values(array_diff(array_unique(array_merge($preferred, $local['preferred'])), $required));

        return [$required, $preferred, $local['title']];
    }

    /** @return array<string, mixed> */
    private function seniority(JobPosting $job, ProfileSnapshot $p, ?array $req): array
    {
        $level = $this->detectLevel($job->title, $req['seniority'] ?? null);
        $yearsReq = $this->yearsRequired((string) $job->description) ?: ((int) ($req['years_required'] ?? 0));
        $notes = ["Role reads as {$level}-level".($yearsReq ? ", asks for ~{$yearsReq}+ years" : '').'.'];

        if ($p->years === null) {
            $pct = 0.5;
            $notes[] = 'Add your years of experience to your profile for a real comparison.';
        } elseif ($yearsReq > 0) {
            $pct = max(0.15, min(1.0, $p->years / $yearsReq));
            $notes[] = "You have about {$p->years} years of experience on your CV.";
        } else {
            $mine = $p->years < 2 ? 'junior' : ($p->years < 5 ? 'mid' : 'senior');
            $order = ['junior' => 0, 'mid' => 1, 'senior' => 2];
            $gap = abs($order[$mine] - $order[$level]);
            $pct = [1.0, 0.6, 0.25][$gap];
            $notes[] = "Your ~{$p->years} years read as {$mine}-level.";
        }

        if ($p->levels !== [] && ! in_array($level, $p->levels, true)) {
            $pct *= 0.6;
            $notes[] = "{$level} roles are outside your selected levels, so this is down-weighted (not excluded).";
        }

        return $this->factor($pct, implode(' ', $notes));
    }

    private function detectLevel(string $title, ?string $aiLevel): string
    {
        $t = Str::lower($title);
        if (preg_match('/\b(intern|internship|junior|jr\.?|entry[- ]level|graduate|trainee|apprentice|associate)\b/', $t)) {
            return 'junior';
        }
        if (preg_match('/\b(senior|sr\.?|staff|principal|lead|head of|architect|director|manager)\b/', $t)) {
            return 'senior';
        }
        if ($aiLevel && in_array($aiLevel, ['junior', 'mid', 'senior', 'lead'], true)) {
            return $aiLevel === 'lead' ? 'senior' : $aiLevel;
        }

        return 'mid';
    }

    private function yearsRequired(string $description): int
    {
        if (preg_match('/(\d{1,2})\s*\+?\s*(?:-\s*\d{1,2}\s*)?(?:years|yrs)\b[^.\n]{0,40}?(?:experience|exp)/i', Str::limit($description, 8000, ''), $m)) {
            $n = (int) $m[1];

            return $n > 0 && $n <= 20 ? $n : 0;
        }

        return 0;
    }

    /** @return array<string, mixed> */
    private function salary(JobPosting $job, ProfileSnapshot $p): array
    {
        if ($why = SalaryMath::incomparableReason($job, $p)) {
            return $this->na($why);
        }

        $annual = SalaryMath::annualTop($job);
        $pct = min(1.0, $annual / max(1, $p->salaryMinAnnual));

        return $this->factor($pct, sprintf('Posting pays up to ~%s %s/year; you want at least %s.', number_format($annual), $p->salaryCurrency, number_format($p->salaryMinAnnual)));
    }

    /** @return array<string, mixed> */
    private function factor(float $pct, string $detail): array
    {
        return ['applicable' => true, 'pct' => round(max(0, min(1, $pct)), 3), 'detail' => $detail];
    }

    /** @return array<string, mixed> */
    private function na(string $why): array
    {
        return ['applicable' => false, 'pct' => 0.0, 'detail' => $why.' (weight redistributed to the other factors)'];
    }

    /** @param array<string, array<string, mixed>> $factors */
    private function rationale(array $factors, array $matched, array $missing, array $roleMatch): string
    {
        $parts = [];
        if ($roleMatch) {
            $parts[] = 'The title fits your target role "'.$roleMatch[0].'".';
        }
        $parts[] = $matched
            ? 'Skill overlap: '.implode(', ', array_slice($matched, 0, 8)).'.'
            : 'No overlap found with your listed skills.';
        if ($missing) {
            $parts[] = 'Not on your profile: '.implode(', ', array_slice($missing, 0, 6)).'.';
        }
        $best = collect($factors)->filter(fn ($f) => $f['applicable'])->sortByDesc('pct')->keys()->first();
        $worst = collect($factors)->filter(fn ($f) => $f['applicable'])->sortBy('pct')->keys()->first();
        if ($best && $worst && $best !== $worst) {
            $parts[] = 'Strongest factor: '.Str::lower(self::LABELS[$best]).'; weakest: '.Str::lower(self::LABELS[$worst]).'.';
        }

        return implode(' ', $parts);
    }
}
