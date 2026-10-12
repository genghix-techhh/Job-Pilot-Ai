<?php

namespace App\Services\Insights;

use App\Enums\ApplicationStatus as S;
use App\Models\Application;
use App\Models\ApplicationEvent;
use App\Models\Interview;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\JobSource;
use App\Models\User;
use App\Services\Matching\MatchService;
use App\Services\Matching\SkillCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read-only analytics over YOUR data: how the search is going (funnel, response rates, which sources
 * pay off) and what the market asks for that you do not (skill gaps). No external calls, no AI.
 */
class InsightsService
{
    /** Statuses that mean "somebody answered after you applied". */
    private const RESPONDED = [S::ResponseReceived, S::Assessment, S::Interview, S::Offer, S::Rejected];

    public function __construct(private readonly MatchService $matches) {}

    /** @return array<string, mixed> */
    public function build(User $user, int $days = 90): array
    {
        $since = now()->subDays($days);
        $profile = $this->matches->activeProfile();

        return [
            'days' => $days,
            'since' => $since,
            'hasProfile' => (bool) $profile,
            'funnel' => $this->funnel($user, $since, $profile?->profile_version),
            'response' => $this->responseStats($user, $since),
            'bySource' => $this->bySource($user, $since, $profile?->profile_version),
            'histogram' => $profile ? $this->histogram($user, $profile->profile_version) : null,
            'weekly' => $this->weekly($user),
            'skills' => $profile ? $this->skillDemand($user, $profile) : null,
            'salary' => $this->salary(),
            'eligibility' => $profile ? $this->eligibilityMix($user, $profile->profile_version) : null,
        ];
    }

    /** @return list<array{key: string, label: string, count: int, pct: ?int, note: string}> */
    private function funnel(User $user, Carbon $since, ?int $profileVersion): array
    {
        $jobs = JobPosting::query()->where('first_seen_at', '>=', $since)->whereNull('duplicate_of')->count();
        $good = $profileVersion === null ? null : JobMatch::query()->where('user_id', $user->id)->where('profile_version', $profileVersion)
            ->whereIn('tier', ['strong', 'potential'])->whereNull('exclusion')
            ->whereHas('job', fn ($j) => $j->where('first_seen_at', '>=', $since)->whereNull('duplicate_of'))->count();

        $cohort = fn () => Application::query()->where('user_id', $user->id)->where('created_at', '>=', $since);
        $drafts = $cohort()->count();
        $approved = $cohort()->whereNotNull('approved_at')->count();
        $submitted = $cohort()->whereNotNull('submitted_at')->count();
        $responded = $cohort()->whereNotNull('submitted_at')->whereIn('status', array_map(fn ($s) => $s->value, self::RESPONDED))->count();
        $interviews = $cohort()->whereNotNull('submitted_at')->where(fn ($q) => $q->whereIn('status', [S::Interview->value, S::Offer->value])
            ->orWhereIn('id', Interview::query()->where('kind', 'interview')->whereIn('state', ['confirmed', 'completed'])->select('application_id')))->count();
        $offers = $cohort()->where('status', S::Offer->value)->count();

        $rows = [
            ['jobs', 'Jobs found', $jobs, 'New postings from your sources (duplicates removed).'],
            ['good', 'Good matches', $good, 'Strong or potential, not excluded by your rules.'],
            ['drafts', 'Drafts started', $drafts, 'Applications you created a draft for.'],
            ['approved', 'Approved', $approved, 'Drafts you reviewed and approved.'],
            ['submitted', 'Submitted', $submitted, 'Confirmed as actually sent.'],
            ['responded', 'Heard back', $responded, 'Any reply after submitting (including rejections).'],
            ['interviews', 'Interviews', $interviews, 'Reached the interview stage.'],
            ['offers', 'Offers', $offers, 'Offers received.'],
        ];

        $out = [];
        $prev = null;
        foreach ($rows as [$key, $label, $count, $note]) {
            $out[] = ['key' => $key, 'label' => $label, 'count' => (int) ($count ?? 0), 'unavailable' => $count === null,
                'pct' => $prev !== null && $prev > 0 && $count !== null ? (int) round($count / $prev * 100) : null, 'note' => $note];
            if ($count !== null) {
                $prev = $count;
            }
        }

        return $out;
    }

    /** @return array{submitted: int, responded: int, rate: ?int, median_days: ?float, pending: int} */
    private function responseStats(User $user, Carbon $since): array
    {
        $submitted = Application::query()->where('user_id', $user->id)->whereNotNull('submitted_at')->where('submitted_at', '>=', $since)->get(['id', 'status', 'submitted_at']);
        $respondedValues = array_map(fn ($s) => $s->value, self::RESPONDED);
        $responded = $submitted->filter(fn ($a) => in_array($a->status->value, $respondedValues, true));

        $firstResponse = ApplicationEvent::query()->whereIn('application_id', $responded->pluck('id'))->whereIn('to_status', $respondedValues)
            ->select('application_id', DB::raw('MIN(occurred_at) as first_at'))->groupBy('application_id')->pluck('first_at', 'application_id');

        $durations = $responded->map(function ($a) use ($firstResponse) {
            $at = $firstResponse[$a->id] ?? null;

            return $at ? max(0.0, Carbon::parse($at, 'UTC')->diffInHours($a->submitted_at, true) / 24) : null;
        })->filter(fn ($d) => $d !== null)->sort()->values();

        $median = null;
        if ($durations->isNotEmpty()) {
            $n = $durations->count();
            $median = round($n % 2 ? $durations[intdiv($n, 2)] : ($durations[$n / 2 - 1] + $durations[$n / 2]) / 2, 1);
        }

        return [
            'submitted' => $submitted->count(),
            'responded' => $responded->count(),
            'rate' => $submitted->count() > 0 ? (int) round($responded->count() / $submitted->count() * 100) : null,
            'median_days' => $median,
            'pending' => $submitted->count() - $responded->count(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function bySource(User $user, Carbon $since, ?int $profileVersion): array
    {
        $jobs = JobPosting::query()->where('first_seen_at', '>=', $since)->whereNull('duplicate_of')
            ->select('source_id', DB::raw('COUNT(*) as c'))->groupBy('source_id')->pluck('c', 'source_id');

        $scores = $profileVersion === null ? collect() : JobMatch::query()->where('job_matches.user_id', $user->id)->where('profile_version', $profileVersion)
            ->join('job_postings', 'job_postings.id', '=', 'job_matches.job_id')->where('job_postings.first_seen_at', '>=', $since)
            ->select('job_postings.source_id', DB::raw('AVG(job_matches.score) as avg_score'),
                DB::raw("SUM(CASE WHEN job_matches.tier IN ('strong','potential') AND job_matches.exclusion IS NULL THEN 1 ELSE 0 END) as good"))
            ->groupBy('job_postings.source_id')->get()->keyBy('source_id');

        $apps = Application::query()->where('applications.user_id', $user->id)->where('applications.created_at', '>=', $since)
            ->join('job_postings', 'job_postings.id', '=', 'applications.job_id')->get(['job_postings.source_id', 'applications.status', 'applications.submitted_at'])
            ->groupBy('source_id');

        $respondedValues = array_map(fn ($s) => $s->value, self::RESPONDED);

        return JobSource::query()->orderBy('name')->get()->map(function (JobSource $s) use ($jobs, $scores, $apps, $respondedValues) {
            $a = $apps->get($s->id, collect());
            $submitted = $a->filter(fn ($x) => $x->submitted_at !== null);
            $responded = $submitted->filter(fn ($x) => in_array(is_object($x->status) ? $x->status->value : $x->status, $respondedValues, true));

            return [
                'id' => $s->id, 'name' => $s->name, 'type' => $s->type->label(),
                'jobs' => (int) ($jobs[$s->id] ?? 0),
                'avg_score' => isset($scores[$s->id]) ? round((float) $scores[$s->id]->avg_score) : null,
                'good' => (int) ($scores[$s->id]->good ?? 0),
                'applications' => $a->count(), 'submitted' => $submitted->count(), 'responded' => $responded->count(),
                'rate' => $submitted->count() > 0 ? (int) round($responded->count() / $submitted->count() * 100) : null,
            ];
        })->filter(fn ($r) => $r['jobs'] > 0 || $r['applications'] > 0)->sortByDesc('good')->values()->all();
    }

    /** @return list<array{label: string, count: int}> ten score buckets for the current profile version */
    private function histogram(User $user, int $profileVersion): array
    {
        $scores = JobMatch::query()->where('user_id', $user->id)->where('profile_version', $profileVersion)
            ->whereHas('job', fn ($j) => $j->where('state', 'active')->whereNull('duplicate_of'))->pluck('score');

        $buckets = array_fill(0, 10, 0);
        foreach ($scores as $s) {
            $buckets[min(9, (int) floor($s / 10))]++;
        }

        return array_map(fn ($i) => ['label' => ($i * 10).($i === 9 ? '–100' : '–'.($i * 10 + 9)), 'count' => $buckets[$i]], array_keys($buckets));
    }

    /** @return list<array{label: string, jobs: int, submitted: int}> last 8 weeks, oldest first */
    private function weekly(User $user): array
    {
        $out = [];
        for ($i = 7; $i >= 0; $i--) {
            $start = now()->startOfWeek()->subWeeks($i);
            $end = $start->copy()->addWeek();
            $out[] = [
                'label' => $start->format('M j'),
                'jobs' => JobPosting::query()->where('first_seen_at', '>=', $start)->where('first_seen_at', '<', $end)->whereNull('duplicate_of')->count(),
                'submitted' => Application::query()->where('user_id', $user->id)->where('submitted_at', '>=', $start)->where('submitted_at', '<', $end)->count(),
            ];
        }

        return $out;
    }

    /**
     * What active postings ask for, whether you have it, and which missing skills appear most in the
     * jobs that are otherwise good matches (the cheapest things to learn for the biggest payoff).
     *
     * @return array<string, mixed>
     */
    private function skillDemand(User $user, $profile): array
    {
        $counts = [];
        $total = 0;
        JobPosting::query()->where('state', 'active')->whereNull('duplicate_of')->whereNotNull('skills')->select(['id', 'skills'])
            ->chunkById(500, function ($jobs) use (&$counts, &$total) {
                foreach ($jobs as $job) {
                    $total++;
                    foreach (array_unique((array) $job->skills) as $skill) {
                        $counts[$skill] = ($counts[$skill] ?? 0) + 1;
                    }
                }
            });
        arsort($counts);
        $top = array_slice($counts, 0, 20, true);

        $mine = array_map('strtolower', $profile->skillList());
        $demand = [];
        foreach ($top as $skill => $n) {
            $demand[] = ['skill' => $skill, 'count' => $n, 'pct' => $total ? (int) round($n / $total * 100) : 0, 'have' => in_array(strtolower($skill), $mine, true)];
        }

        // Gaps: missing skills in jobs that score decently and are open to you.
        $gapCounts = [];
        JobMatch::query()->where('user_id', $user->id)->where('profile_version', $profile->profile_version)
            ->whereIn('tier', ['strong', 'potential'])->whereNull('exclusion')->where('eligibility_status', '!=', 'restricted')
            ->whereHas('job', fn ($j) => $j->where('state', 'active')->whereNull('duplicate_of'))
            ->select(['id', 'missing_skills'])->chunkById(300, function ($rows) use (&$gapCounts) {
                foreach ($rows as $row) {
                    foreach ((array) $row->missing_skills as $s) {
                        $gapCounts[$s] = ($gapCounts[$s] ?? 0) + 1;
                    }
                }
            });
        arsort($gapCounts);

        return [
            'jobs' => $total,
            'demand' => $demand,
            'coverage' => $demand ? (int) round(count(array_filter($demand, fn ($d) => $d['have'])) / count($demand) * 100) : null,
            'gaps' => array_map(fn ($s, $n) => ['skill' => $s, 'count' => $n, 'category' => SkillCatalog::category($s)], array_keys(array_slice($gapCounts, 0, 8, true)), array_slice($gapCounts, 0, 8, true)),
        ];
    }

    /** @return array{currency: string, n: int, p25: int, median: int, p75: int}|null yearly salaries stated in postings (single currency, never converted) */
    private function salary(): ?array
    {
        $rows = JobPosting::query()->where('state', 'active')->whereNull('duplicate_of')->where('salary_period', 'year')->whereNotNull('salary_max')
            ->select('salary_currency', 'salary_max')->get()->groupBy('salary_currency')->sortByDesc(fn ($g) => $g->count());
        $currency = $rows->keys()->first();
        if (! $currency) {
            return null;
        }
        $vals = $rows[$currency]->pluck('salary_max')->map(fn ($v) => (int) $v)->sort()->values();
        if ($vals->count() < 5) {
            return null;
        }
        $q = fn (float $p) => (int) $vals[(int) min($vals->count() - 1, floor($p * ($vals->count() - 1)))];

        return ['currency' => (string) $currency, 'n' => $vals->count(), 'p25' => $q(0.25), 'median' => $q(0.5), 'p75' => $q(0.75)];
    }

    /** @return array<string, int> */
    private function eligibilityMix(User $user, int $profileVersion): array
    {
        $mix = JobMatch::query()->where('user_id', $user->id)->where('profile_version', $profileVersion)->whereNull('exclusion')
            ->whereHas('job', fn ($j) => $j->where('state', 'active')->whereNull('duplicate_of'))
            ->select('eligibility_status', DB::raw('COUNT(*) as c'))->groupBy('eligibility_status')->pluck('c', 'eligibility_status')->all();

        return ['eligible' => (int) ($mix['eligible'] ?? 0), 'unclear' => (int) ($mix['unclear'] ?? 0), 'restricted' => (int) ($mix['restricted'] ?? 0)];
    }
}
