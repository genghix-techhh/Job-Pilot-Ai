<?php

namespace App\Services\Jobs;

use App\Models\Application;
use App\Models\CandidateProfile;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\User;
use App\Services\Matching\SkillCatalog;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one definition of "which jobs does the owner see": used by the feed, saved searches and the
 * e-mail digest so all three always agree. Joins the current match (profile version + job checksum)
 * so scores, tiers and eligibility can be filtered and sorted in SQL.
 */
class JobFeedQuery
{
    /** Filters that make up a saved search (view/sort are presentation, not part of the search). */
    public const SEARCH_KEYS = ['q', 'role', 'source', 'posted', 'min_score', 'eligibility', 'kind', 'status', 'salary', 'remote', 'show_low', 'show_excluded'];

    /** @return array<string, array<int, string>> */
    public static function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'string', 'max:60'],
            'source' => ['nullable', 'integer'],
            'posted' => ['nullable', 'in:1,3,7,14,30'],
            'min_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'eligibility' => ['nullable', 'in:eligible,unclear,restricted'],
            'kind' => ['nullable', 'in:employment,freelance'],
            'status' => ['nullable', 'in:new,saved,hidden,applied'],
            'salary' => ['nullable', 'boolean'],
            'remote' => ['nullable', 'boolean'],
            'show_low' => ['nullable', 'boolean'],
            'show_excluded' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'in:score,date'],
            'view' => ['nullable', 'in:cards,list'],
        ];
    }

    /**
     * @param  array<string, mixed>  $f  validated filters
     * @return Builder<JobPosting>
     */
    public function build(User $user, ?CandidateProfile $profile, array $f): Builder
    {
        $q = JobPosting::query()->with('source')
            ->select('job_postings.*')
            ->where('job_postings.state', 'active')->whereNull('job_postings.duplicate_of');

        if ($profile) {
            $q->leftJoin('job_matches as m', function ($j) use ($user, $profile) {
                $j->on('m.job_id', '=', 'job_postings.id')
                    ->where('m.user_id', $user->id)
                    ->where('m.profile_version', $profile->profile_version)
                    ->on('m.job_checksum', '=', 'job_postings.description_checksum');
            })->addSelect([
                'm.score as match_score', 'm.tier as match_tier', 'm.eligibility_status as match_eligibility',
                'm.eligibility_note as match_eligibility_note', 'm.ai_status as match_ai_status',
                'm.matched_skills as match_matched_skills', 'm.exclusion as match_exclusion',
            ]);
        }

        if ($t = trim((string) ($f['q'] ?? ''))) {
            $like = '%'.addcslashes($t, '%_\\').'%';
            $q->where(fn ($w) => $w->where('job_postings.title', 'like', $like)
                ->orWhere('job_postings.company', 'like', $like)
                ->orWhere('job_postings.description', 'like', $like));
        }
        if (! empty($f['role'])) {
            $words = SkillCatalog::ROLE_KEYWORDS[$f['role']] ?? [];
            if ($f['role'] === 'Freelance Projects') {
                $q->where(fn ($w) => $w->where('job_postings.kind', 'freelance')->orWhereIn('job_postings.employment_type', ['contract']));
            } elseif ($words) {
                $q->where(function ($w) use ($words) {
                    foreach ($words as $word) {
                        $w->orWhere('job_postings.title', 'like', '%'.addcslashes($word, '%_\\').'%');
                    }
                });
            }
        }
        if (! empty($f['source'])) {
            $q->where('job_postings.source_id', $f['source']);
        }
        if (! empty($f['posted'])) {
            $q->whereRaw('COALESCE(job_postings.posted_at, job_postings.first_seen_at) >= ?', [now()->subDays((int) $f['posted'])]);
        }
        if (! empty($f['kind'])) {
            $q->where('job_postings.kind', $f['kind']);
        }
        if (! empty($f['salary'])) {
            $q->where(fn ($w) => $w->whereNotNull('job_postings.salary_min')->orWhereNotNull('job_postings.salary_max'));
        }
        if (! empty($f['remote'])) {
            $q->where('job_postings.is_remote', true);
        }

        $status = $f['status'] ?? null;
        if ($status === 'applied') {
            $q->whereIn('job_postings.id', Application::query()->where('user_id', $user->id)->select('job_id'));
        } elseif ($status) {
            $q->where('job_postings.user_status', $status);
        } else {
            $q->where('job_postings.user_status', '!=', 'hidden');
        }

        if ($profile) {
            if (! empty($f['eligibility'])) {
                $q->where('m.eligibility_status', $f['eligibility']);
            }
            if (isset($f['min_score'])) {
                $q->where('m.score', '>=', (int) $f['min_score']);
            } elseif (empty($f['show_low']) && ! settings()->get('show_low_matches')) {
                // Low matches are hidden from the main queue unless the owner asks for them.
                $q->where(fn ($w) => $w->whereNull('m.score')->orWhere('m.score', '>=', settings()->potentialThreshold()));
            }
            if (empty($f['show_excluded'])) {
                // The owner's own rules (blocked company, keyword, salary floor).
                $q->whereNull('m.exclusion');
            }
        }

        return $q;
    }

    public function order(Builder $q, string $sort, bool $hasProfile): Builder
    {
        if ($sort === 'score' && $hasProfile) {
            $q->orderByRaw('(m.score IS NULL) ASC')->orderByDesc('m.score');
        }

        return $q->orderByRaw('COALESCE(job_postings.posted_at, job_postings.first_seen_at) DESC');
    }

    /** How many otherwise-visible jobs are hidden as low matches / by exclusion rules. */
    public function hiddenCounts(User $user, CandidateProfile $profile, array $f): array
    {
        $base = fn () => JobMatch::query()->where('user_id', $user->id)->where('profile_version', $profile->profile_version)
            ->whereHas('job', fn ($j) => $j->where('state', 'active')->whereNull('duplicate_of')->where('user_status', '!=', 'hidden'));

        $low = 0;
        if (empty($f['show_low']) && ! isset($f['min_score'])) {
            $low = $base()->whereNull('exclusion')->where('score', '<', settings()->potentialThreshold())->count();
        }
        $excluded = empty($f['show_excluded']) ? $base()->whereNotNull('exclusion')->count() : 0;

        return ['low' => $low, 'excluded' => $excluded];
    }
}
