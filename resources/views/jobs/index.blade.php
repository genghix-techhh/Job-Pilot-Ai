<x-layouts.app title="Job Feed">
    <x-page-header title="Job Feed" subtitle="Openings from the sources you enabled, ranked against your reviewed CV. The score is a prioritisation aid — not a prediction that anyone will reply.">
        <x-slot name="actions">
            <a href="{{ route('jobs.create') }}" class="btn btn-secondary"><x-icon name="plus" class="h-4 w-4" /> Add a job link</a>
            <form method="POST" action="{{ route('sources.sync-all') }}">@csrf
                <button class="btn btn-accent"><x-icon name="refresh" class="h-4 w-4" /> Run discovery</button>
            </form>
        </x-slot>
    </x-page-header>

    {{-- Saved searches --}}
    @if ($savedSearches->isNotEmpty())
        <section aria-label="Saved searches" class="flex flex-wrap items-center gap-2">
            <span class="text-xs font-semibold uppercase tracking-wide text-slate-400">Saved searches</span>
            @foreach ($savedSearches as $s)
                <span class="inline-flex items-center overflow-hidden rounded-full bg-white text-sm shadow-sm ring-1 ring-inset ring-slate-300">
                    <a href="{{ route('jobs.index', $s->filters) }}" class="px-3 py-1 font-medium text-slate-800 hover:bg-amber-50">{{ $s->name }}</a>
                    <form method="POST" action="{{ route('searches.update', $s) }}">@csrf @method('PATCH')
                        <button class="border-l border-slate-200 px-2 py-1 {{ $s->digest ? 'text-amber-600' : 'text-slate-300' }} hover:bg-slate-50" title="{{ $s->digest ? 'Included in your e-mail digest (click to remove)' : 'Not in your digest (click to include)' }}" aria-label="Toggle digest for {{ $s->name }}" data-no-lock><x-icon name="mail" class="h-4 w-4" /></button>
                    </form>
                    <form method="POST" action="{{ route('searches.destroy', $s) }}" x-data="confirmSubmit('Delete this saved search?')" @submit="check($event)">@csrf @method('DELETE')
                        <button class="border-l border-slate-200 px-2 py-1 text-slate-400 hover:bg-rose-50 hover:text-rose-600" aria-label="Delete saved search {{ $s->name }}"><x-icon name="x" class="h-4 w-4" /></button>
                    </form>
                </span>
            @endforeach
        </section>
    @endif

    {{-- Filters --}}
    <form method="GET" action="{{ route('jobs.index') }}" class="card p-4" x-data="{ open: {{ collect($filters)->except(['sort', 'view'])->filter()->isNotEmpty() ? 'true' : 'false' }} }" aria-label="Filter jobs">
        <div class="flex flex-wrap items-center gap-3">
            <div class="relative min-w-0 flex-1 basis-64">
                <x-icon name="search" class="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
                <label for="f-q" class="sr-only">Search jobs</label>
                <input id="f-q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search title, company or description" class="input pl-9" maxlength="100">
            </div>
            <label class="flex items-center gap-2 text-sm text-slate-600">Sort
                <select name="sort" class="input !w-auto" onchange="this.form.submit()">
                    @if ($profile)<option value="score" @selected($sort === 'score')>Best match</option>@endif
                    <option value="date" @selected($sort === 'date')>Newest</option>
                </select>
            </label>
            <div class="inline-flex rounded-lg ring-1 ring-inset ring-slate-300" role="group" aria-label="Layout">
                <button name="view" value="cards" class="btn btn-sm rounded-r-none {{ $view === 'cards' ? 'bg-slate-900 text-white' : 'bg-white text-slate-600' }}" aria-pressed="{{ $view === 'cards' ? 'true' : 'false' }}" data-no-lock>Cards</button>
                <button name="view" value="list" class="btn btn-sm rounded-l-none {{ $view === 'list' ? 'bg-slate-900 text-white' : 'bg-white text-slate-600' }}" aria-pressed="{{ $view === 'list' ? 'true' : 'false' }}" data-no-lock>List</button>
            </div>
            <button type="button" class="btn btn-secondary" @click="open = !open" :aria-expanded="open.toString()"><x-icon name="funnel" class="h-4 w-4" /> Filters</button>
        </div>

        <div x-show="open" x-cloak class="mt-4 grid gap-4 border-t border-slate-100 pt-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-field label="Role" name="role">
                <select id="f-role" name="role" class="input"><option value="">Any role</option>
                    @foreach ($roles as $r)<option @selected(($filters['role'] ?? '') === $r)>{{ $r }}</option>@endforeach
                </select>
            </x-field>
            <x-field label="Source" name="source">
                <select id="f-source" name="source" class="input"><option value="">All sources</option>
                    @foreach ($sources as $s)<option value="{{ $s->id }}" @selected((int) ($filters['source'] ?? 0) === $s->id)>{{ $s->name }}</option>@endforeach
                </select>
            </x-field>
            <x-field label="Posted" name="posted">
                <select id="f-posted" name="posted" class="input"><option value="">Any time</option>
                    @foreach ([1 => 'Last 24 hours', 3 => 'Last 3 days', 7 => 'Last 7 days', 14 => 'Last 14 days', 30 => 'Last 30 days'] as $d => $l)<option value="{{ $d }}" @selected((int) ($filters['posted'] ?? 0) === $d)>{{ $l }}</option>@endforeach
                </select>
            </x-field>
            <x-field label="Type" name="kind">
                <select id="f-kind" name="kind" class="input"><option value="">Employment &amp; freelance</option>
                    <option value="employment" @selected(($filters['kind'] ?? '') === 'employment')>Employment only</option>
                    <option value="freelance" @selected(($filters['kind'] ?? '') === 'freelance')>Freelance projects only</option>
                </select>
            </x-field>
            @if ($profile)
                <x-field label="Minimum score" name="min_score">
                    <input id="f-min_score" type="number" name="min_score" min="0" max="100" value="{{ $filters['min_score'] ?? '' }}" class="input" placeholder="e.g. 65">
                </x-field>
                <x-field label="Remote eligibility" name="eligibility">
                    <select id="f-eligibility" name="eligibility" class="input"><option value="">Any</option>
                        @foreach (\App\Enums\EligibilityStatus::cases() as $e)<option value="{{ $e->value }}" @selected(($filters['eligibility'] ?? '') === $e->value)>{{ $e->label() }}</option>@endforeach
                    </select>
                </x-field>
            @endif
            <x-field label="Status" name="status">
                <select id="f-status" name="status" class="input"><option value="">Active (not hidden)</option>
                    @foreach (['new' => 'New', 'saved' => 'Saved', 'hidden' => 'Hidden', 'applied' => 'Has an application'] as $v => $l)<option value="{{ $v }}" @selected(($filters['status'] ?? '') === $v)>{{ $l }}</option>@endforeach
                </select>
            </x-field>
            <div class="flex flex-col justify-end gap-2 text-sm text-slate-700">
                <label class="flex items-center gap-2"><input type="checkbox" class="check" name="salary" value="1" @checked(! empty($filters['salary']))> Salary stated</label>
                <label class="flex items-center gap-2"><input type="checkbox" class="check" name="remote" value="1" @checked(! empty($filters['remote']))> Remote only</label>
                @if ($profile)
                    <label class="flex items-center gap-2"><input type="checkbox" class="check" name="show_low" value="1" @checked(! empty($filters['show_low']))> Include low matches</label>
                    <label class="flex items-center gap-2"><input type="checkbox" class="check" name="show_excluded" value="1" @checked(! empty($filters['show_excluded']))> Include jobs my rules exclude</label>
                @endif
            </div>
            <div class="flex flex-wrap items-end gap-2 sm:col-span-2 lg:col-span-4">
                <button class="btn btn-primary">Apply filters</button>
                <a href="{{ route('jobs.index') }}" class="btn btn-ghost">Reset</a>
                @if (collect($filters)->only(\App\Services\Jobs\JobFeedQuery::SEARCH_KEYS)->filter()->isNotEmpty())
                    <span class="ml-auto flex items-end gap-2">
                        <label class="sr-only" for="ss-name">Name for this search</label>
                        <input id="ss-name" form="save-search" name="name" maxlength="80" class="input !w-48" placeholder="Name this search…" required>
                        <button form="save-search" class="btn btn-secondary" data-no-lock><x-icon name="bookmark" class="h-4 w-4" /> Save search</button>
                    </span>
                @endif
            </div>
        </div>
    </form>

    {{-- Separate forms referenced through the HTML "form" attribute (nested forms are invalid) --}}
    <form id="save-search" method="POST" action="{{ route('searches.store') }}" class="hidden">
        @csrf
        @foreach (collect($filters)->only(\App\Services\Jobs\JobFeedQuery::SEARCH_KEYS)->filter() as $k => $v)
            <input type="hidden" name="{{ $k }}" value="{{ $v }}">
        @endforeach
    </form>
    <form id="bulk-form" method="POST" action="{{ route('jobs.bulk') }}" class="hidden">@csrf</form>

    @unless ($profile)
        <div class="flex flex-wrap items-center gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <x-icon name="info" class="h-5 w-5 text-amber-600" />
            <p class="flex-1">Jobs are listed but <strong>not scored</strong> — review and save your profile to enable ranking.</p>
            <a href="{{ route('profile.edit') }}" class="btn btn-accent btn-sm">CV &amp; Profile</a>
        </div>
    @endunless

    @if ($jobs->isEmpty())
        @if ($totalActive === 0)
            <x-empty icon="globe" title="No jobs yet — add where to look" text="JobPilot only reads sources that allow it. Add a company's Greenhouse board token or Lever site, the Remote OK feed, or an RSS feed; or paste a job link yourself.">
                <a href="{{ route('sources.index') }}" class="btn btn-accent">Add a source</a>
                <a href="{{ route('jobs.create') }}" class="btn btn-secondary">Paste a job link</a>
            </x-empty>
        @else
            <x-empty icon="funnel" title="Nothing matches these filters" text="Try clearing a filter{{ $hidden['low'] ? ' or include the '.$hidden['low'].' low match(es) hidden by default' : '' }}.">
                <a href="{{ route('jobs.index') }}" class="btn btn-secondary">Reset filters</a>
                @if ($hidden['low'])<a href="{{ route('jobs.index', ['show_low' => 1]) }}" class="btn btn-secondary">Show low matches</a>@endif
            </x-empty>
        @endif
    @else
        <p class="text-sm text-slate-500">
            {{ $jobs->total() }} job(s)
            @if ($hidden['low'])· <a class="font-medium text-amber-700 hover:underline" href="{{ route('jobs.index', array_merge(request()->query(), ['show_low' => 1])) }}">{{ $hidden['low'] }} low match(es) hidden</a>@endif
            @if ($hidden['excluded'])· <a class="font-medium text-slate-600 hover:underline" href="{{ route('jobs.index', array_merge(request()->query(), ['show_excluded' => 1])) }}">{{ $hidden['excluded'] }} excluded by your rules</a>@endif
        </p>

        {{-- Bulk actions (checkboxes below are tied to #bulk-form with the form="" attribute) --}}
        <div class="flex flex-wrap items-center gap-2 text-sm" x-data="{ n: 0 }" @change.window="n = document.querySelectorAll('input[name=\'ids[]\']:checked').length">
            <label class="flex items-center gap-2 text-slate-600"><input type="checkbox" class="check" aria-label="Select all jobs on this page" @change="document.querySelectorAll('input[name=\'ids[]\']').forEach(c => c.checked = $event.target.checked); n = $event.target.checked ? document.querySelectorAll('input[name=\'ids[]\']').length : 0"> Select all on page</label>
            <span class="text-slate-400" x-show="n > 0" x-cloak x-text="n + ' selected'"></span>
            <button form="bulk-form" name="action" value="save" class="btn btn-secondary btn-sm" x-show="n > 0" x-cloak data-no-lock><x-icon name="bookmark" class="h-4 w-4" /> Save selected</button>
            <button form="bulk-form" name="action" value="hide" class="btn btn-secondary btn-sm" x-show="n > 0" x-cloak data-no-lock><x-icon name="eye-off" class="h-4 w-4" /> Hide selected</button>
            @if (($filters['status'] ?? '') === 'hidden')<button form="bulk-form" name="action" value="unhide" class="btn btn-secondary btn-sm" x-show="n > 0" x-cloak data-no-lock>Restore selected</button>@endif
        </div>

        @if ($view === 'list')
            <div class="table-wrap">
                <table class="table-base">
                    <thead><tr><th scope="col"><span class="sr-only">Select</span></th><th scope="col">Score</th><th scope="col">Job</th><th scope="col">Source</th><th scope="col">Location</th><th scope="col">Posted</th><th scope="col">Eligibility</th><th scope="col">Pay</th></tr></thead>
                    <tbody>
                    @foreach ($jobs as $job)
                        @php
                            $tier = \App\Enums\MatchTier::tryFrom((string) ($job->match_tier ?? '')) ?? \App\Enums\MatchTier::Unscored;
                            $elig = \App\Enums\EligibilityStatus::tryFrom((string) ($job->match_eligibility ?? ''));
                        @endphp
                        <tr class="hover:bg-slate-50">
                            <td><input type="checkbox" form="bulk-form" name="ids[]" value="{{ $job->id }}" class="check" aria-label="Select {{ $job->title }}"></td>
                            <td><x-score :value="$job->match_score ?? null" :tier="$tier->value" :size="40" /></td>
                            <td class="min-w-56">
                                <a href="{{ route('jobs.show', $job) }}" class="font-medium text-slate-900 hover:text-amber-700 hover:underline">{{ $job->title }}</a>
                                <p class="text-xs text-slate-500">{{ $job->company ?: 'Company not stated' }}</p>
                            </td>
                            <td class="whitespace-nowrap text-slate-600">{{ $job->source->name }}</td>
                            <td class="max-w-48 truncate text-slate-600">{{ $job->location ?: '—' }}</td>
                            <td class="whitespace-nowrap text-slate-600">{{ jp_date($job->posted_at ?? $job->first_seen_at) }}</td>
                            <td>@if ($elig)<x-badge :tone="$elig->tone()">{{ $elig->label() }}</x-badge>@else<span class="text-slate-400">—</span>@endif</td>
                            <td class="whitespace-nowrap text-slate-600">{{ $job->salaryLabel() ?? '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($jobs as $job)
                    @include('jobs._item', ['job' => $job])
                @endforeach
            </div>
        @endif

        <div>{{ $jobs->links() }}</div>
    @endif
</x-layouts.app>
