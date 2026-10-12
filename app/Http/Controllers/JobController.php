<?php

namespace App\Http\Controllers;

use App\Http\Requests\ManualJobRequest;
use App\Models\Application;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\JobSource;
use App\Models\SavedSearch;
use App\Services\Jobs\JobFeedQuery;
use App\Services\Jobs\JobSyncService;
use App\Services\Jobs\JobUpserter;
use App\Services\Jobs\ManualJobService;
use App\Services\Jobs\NormalizedJob;
use App\Services\Matching\EligibilityResolver;
use App\Services\Matching\Geo;
use App\Services\Matching\MatchService;
use App\Services\Matching\SkillCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class JobController extends Controller
{
    public function index(Request $request, MatchService $matches, JobFeedQuery $feed): View
    {
        $user = $request->user();
        $profile = $matches->activeProfile();
        $f = $request->validate(JobFeedQuery::rules());

        $sort = $f['sort'] ?? ($profile ? 'score' : 'date');
        $jobs = $feed->order($feed->build($user, $profile, $f), $sort, (bool) $profile)->paginate(20)->withQueryString();

        return view('jobs.index', [
            'jobs' => $jobs,
            'filters' => $f,
            'profile' => $profile,
            'sources' => JobSource::query()->orderBy('name')->get(['id', 'name', 'type']),
            'roles' => array_keys(SkillCatalog::ROLE_KEYWORDS),
            'view' => $f['view'] ?? 'cards',
            'sort' => $sort,
            'hidden' => $profile ? $feed->hiddenCounts($user, $profile, $f) : ['low' => 0, 'excluded' => 0],
            'totalActive' => JobPosting::query()->where('state', 'active')->whereNull('duplicate_of')->count(),
            'savedSearches' => SavedSearch::query()->where('user_id', $user->id)->orderBy('name')->get(),
        ]);
    }

    public function show(Request $request, JobPosting $job, MatchService $matches, EligibilityResolver $elig): View
    {
        $profile = $matches->activeProfile();
        $job->load('source');

        $match = $profile
            ? JobMatch::query()->where(['user_id' => $request->user()->id, 'job_id' => $job->id, 'profile_version' => $profile->profile_version, 'job_checksum' => $job->description_checksum])->first()
            : null;

        // Without a score we still show eligibility (it needs no profile, only the country setting).
        $eligibility = $match
            ? ['status' => $match->eligibility_status, 'note' => $match->eligibility_note]
            : $elig->resolve($job, Geo::canonicalCountry((string) settings()->get('home_country')));

        return view('jobs.show', [
            'job' => $job,
            'match' => $match,
            'profile' => $profile,
            'eligibility' => $eligibility,
            'application' => Application::query()->where('user_id', $request->user()->id)->where('job_id', $job->id)->first(),
            'duplicates' => JobPosting::query()->with('source')->where('duplicate_of', $job->id)->get(),
            'canonical' => $job->duplicate_of ? JobPosting::with('source')->find($job->duplicate_of) : null,
        ]);
    }

    public function status(Request $request, JobPosting $job): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', 'in:save,hide,unhide,unsave']]);
        $job->update(['user_status' => match ($data['action']) {
            'save' => 'saved', 'hide' => 'hidden', default => 'new',
        }]);

        return back()->with('status', match ($data['action']) {
            'save' => 'Saved for later.', 'hide' => 'Hidden from your feed. Find it again with Status → Hidden.', 'unsave' => 'Removed from saved.', default => 'Restored to your feed.',
        });
    }

    /** Save / hide / restore many jobs from the feed at once. */
    public function bulk(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'action' => ['required', 'in:save,hide,unhide,unsave'],
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
        ], ['ids.required' => 'Tick at least one job first.', 'ids.min' => 'Tick at least one job first.']);

        $n = JobPosting::query()->whereIn('id', $d['ids'])->update(['user_status' => match ($d['action']) {
            'save' => 'saved', 'hide' => 'hidden', default => 'new',
        }]);

        return back()->with('status', match ($d['action']) {
            'save' => "Saved {$n} job(s).", 'hide' => "Hid {$n} job(s). Find them under Status → Hidden.", default => "Restored {$n} job(s).",
        });
    }

    public function details(Request $request, JobPosting $job, JobUpserter $upserter, MatchService $matches): RedirectResponse
    {
        abort_unless($job->is_manual, 403, 'Only manually imported jobs can be edited.');
        $d = $request->validate([
            'title' => ['required', 'string', 'max:250'],
            'company' => ['nullable', 'string', 'max:250'],
            'location' => ['nullable', 'string', 'max:250'],
            'description' => ['nullable', 'string', 'max:60000'],
        ]);

        $attrs = $upserter->attributes(new NormalizedJob(
            title: $d['title'], url: $job->url, company: $d['company'] ?? null,
            description: $d['description'] ?? '', location: $d['location'] ?? null,
            isRemote: $job->is_remote, employmentType: $job->employment_type, kind: $job->kind->value,
            salaryMin: $job->salary_min, salaryMax: $job->salary_max, salaryCurrency: $job->salary_currency, salaryPeriod: $job->salary_period,
            meta: $job->meta ?? [], isManual: true,
            needsDetails: mb_strlen((string) ($d['description'] ?? '')) < 40,
        ));
        unset($attrs['posted_at'], $attrs['source_updated_at'], $attrs['expires_at']);
        $job->update($attrs);

        $matches->scoreMany([$job->refresh()], notify: false);

        return back()->with('status', 'Details saved and the job was re-scored.');
    }

    public function rescore(JobPosting $job, MatchService $matches, JobSyncService $sync): RedirectResponse
    {
        $match = $matches->scoreJob($job);
        if (! $match) {
            return back()->with('error', 'Review and save your profile first — scoring only uses your reviewed data.');
        }
        $queued = $sync->queueAiAnalysis([$match]);

        return back()->with('status', 'Re-scored.'.($queued ? ' An AI analysis was queued.' : ''));
    }

    public function create(): View
    {
        return view('jobs.import');
    }

    public function store(ManualJobRequest $request, ManualJobService $manual): RedirectResponse
    {
        $result = $manual->add($request->validated());

        return redirect()->route('jobs.show', $result['job'])->with('status', $result['created']
            ? ($result['job']->needs_details ? 'Link saved. Add the title, company and description so it can be scored.' : 'Job imported and scored.')
            : 'You had already saved this posting — opened the existing entry.');
    }
}
