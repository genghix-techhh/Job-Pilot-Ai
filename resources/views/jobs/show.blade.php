@php
    use App\Enums\MatchTier;
    $tier = $match?->tier ?? MatchTier::Unscored;
    $elig = $eligibility['status'];
    $remoteOk = $job->source->type === \App\Enums\SourceType::RemoteOk;
    $apply = $job->meta['apply_url'] ?? null;
@endphp
<x-layouts.app :title="$job->title">
    <nav aria-label="Breadcrumb" class="text-sm text-slate-500">
        <a href="{{ route('jobs.index') }}" class="hover:text-amber-700 hover:underline">Job Feed</a> <span aria-hidden="true">/</span> <span class="text-slate-700">{{ \Illuminate\Support\Str::limit($job->title, 60) }}</span>
    </nav>

    @if ($job->state === 'expired')
        <div class="flex items-start gap-3 rounded-xl border border-slate-300 bg-slate-100 p-4 text-sm text-slate-700">
            <x-icon name="clock" class="mt-0.5 h-5 w-5" />
            <p>This posting is <strong>no longer listed</strong> by its source (last seen {{ jp_ago($job->last_seen_at) }}). It may be closed — check before applying.</p>
        </div>
    @endif
    @if ($match?->exclusion)
        <div class="flex items-start gap-3 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900">
            <x-icon name="shield" class="mt-0.5 h-5 w-5 text-rose-600" />
            <p><strong>Excluded by your rules:</strong> {{ $match->exclusion }}. It is hidden from your feed, dashboard and alerts and uses no AI calls. You can still create a draft if you want it. Change the rules under <a class="underline" href="{{ route('settings.edit') }}">Settings</a>.</p>
        </div>
    @endif
    @if ($canonical)
        <div class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900">Same posting also found via <a class="font-medium underline" href="{{ route('jobs.show', $canonical) }}">{{ $canonical->source->name }}</a>; that copy is shown in your feed instead.</div>
    @endif

    <header class="card card-pad">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap gap-1.5">
                    @if ($job->kind === \App\Enums\JobKind::Freelance)<x-badge tone="violet">Freelance project</x-badge>@endif
                    @if ($job->is_remote)<x-badge tone="sky">Remote</x-badge>@endif
                    @if ($job->is_manual)<x-badge>Manually imported</x-badge>@endif
                    @if ($job->needs_details)<x-badge tone="amber">Needs details</x-badge>@endif
                    @if ($job->user_status === 'saved')<x-badge tone="amber">Saved</x-badge>@endif
                    @if ($job->user_status === 'hidden')<x-badge>Hidden</x-badge>@endif
                </div>
                <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">{{ $job->title }}</h1>
                <p class="mt-1 text-slate-600">{{ $job->company ?: 'Company not stated' }}@if ($job->location) · {{ $job->location }}@endif</p>
                <p class="mt-2 text-sm text-slate-500">
                    Source: <strong class="font-medium text-slate-700">{{ $job->source->name }}</strong>
                    @if ($remoteOk) (listing on <a class="underline" href="{{ $job->url }}" rel="noopener">Remote OK</a>)@endif
                    · posted {{ jp_date($job->posted_at ?? $job->first_seen_at) }}
                    · first seen {{ jp_ago($job->first_seen_at) }} · last checked {{ jp_ago($job->last_seen_at) }}
                </p>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <form method="POST" action="{{ route('jobs.status', $job) }}">@csrf @method('PATCH')
                    <input type="hidden" name="action" value="{{ $job->user_status === 'saved' ? 'unsave' : 'save' }}">
                    <button class="btn btn-secondary"><x-icon name="bookmark" class="h-4 w-4" /> {{ $job->user_status === 'saved' ? 'Saved' : 'Save' }}</button>
                </form>
                <form method="POST" action="{{ route('jobs.status', $job) }}">@csrf @method('PATCH')
                    <input type="hidden" name="action" value="{{ $job->user_status === 'hidden' ? 'unhide' : 'hide' }}">
                    <button class="btn btn-secondary"><x-icon name="eye-off" class="h-4 w-4" /> {{ $job->user_status === 'hidden' ? 'Unhide' : 'Hide' }}</button>
                </form>
                <a href="{{ $job->url }}" target="_blank" rel="noopener{{ $remoteOk ? '' : ' noreferrer' }}" class="btn btn-secondary"><x-icon name="external" class="h-4 w-4" /> Open source website</a>
                @if ($application)
                    <a href="{{ route('applications.show', $application) }}" class="btn btn-primary">Open application · {{ $application->status->label() }}</a>
                @else
                    <form method="POST" action="{{ route('jobs.draft', $job) }}">@csrf
                        <button class="btn btn-accent"><x-icon name="pencil" class="h-4 w-4" /> Create draft</button>
                    </form>
                @endif
            </div>
        </div>
        @if (! $application)
            <p class="mt-3 text-xs text-slate-500">Creating a draft only prepares text for you to edit. <strong>Nothing is sent to the employer</strong> until you approve it and apply yourself.</p>
        @endif
    </header>

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- LEFT: posting --}}
        <div class="space-y-6 lg:col-span-2">
            <section class="card card-pad">
                <h2 class="h-section">Details</h2>
                <dl class="mt-3 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-slate-500">Employment type</dt><dd class="font-medium text-slate-800">{{ \Illuminate\Support\Str::headline($job->employment_type) }}</dd></div>
                    <div><dt class="text-slate-500">Compensation</dt><dd class="font-medium text-slate-800">{{ $job->salaryLabel() ?? 'Not stated' }}</dd></div>
                    <div><dt class="text-slate-500">Declared remote scope</dt><dd class="font-medium text-slate-800">{{ ['worldwide' => 'Worldwide / anywhere', 'region' => 'Specific regions', 'country' => 'Specific country', 'hybrid' => 'Hybrid', 'onsite' => 'On-site', 'unclear' => 'Not clearly stated'][$job->remote_scope] ?? $job->remote_scope }}</dd></div>
                    <div><dt class="text-slate-500">Eligible countries</dt><dd class="font-medium text-slate-800">{{ $job->eligible_countries ? implode(', ', $job->eligible_countries) : 'None stated' }}</dd></div>
                    @if ($job->expires_at)<div><dt class="text-slate-500">Expires</dt><dd class="font-medium text-slate-800">{{ jp_date($job->expires_at) }}</dd></div>@endif
                    @if (! empty($job->meta['departments']))<div><dt class="text-slate-500">Department</dt><dd class="font-medium text-slate-800">{{ implode(', ', $job->meta['departments']) }}</dd></div>@endif
                    @if (! empty($job->meta['tags']))<div class="sm:col-span-2"><dt class="text-slate-500">Tags</dt><dd class="mt-1 flex flex-wrap gap-1.5">@foreach ($job->meta['tags'] as $t)<x-badge>{{ $t }}</x-badge>@endforeach</dd></div>@endif
                </dl>
                @if ($job->skills)
                    <div class="mt-4">
                        <p class="text-sm text-slate-500">Skills detected in the posting</p>
                        <div class="mt-1.5 flex flex-wrap gap-1.5">
                            @foreach ($job->skills as $s)
                                <x-badge :tone="in_array($s, $match?->matched_skills ?? [], true) ? 'emerald' : 'slate'">{{ $s }}</x-badge>
                            @endforeach
                        </div>
                        @if ($match)<p class="mt-1 text-xs text-slate-400">Green = also on your profile.</p>@endif
                    </div>
                @endif
                @if ($apply)
                    <p class="mt-4 text-sm text-slate-600">Application link given by the source: <a class="break-all text-amber-700 underline" href="{{ $apply }}" target="_blank" rel="noopener noreferrer">{{ \Illuminate\Support\Str::limit($apply, 80) }}</a></p>
                @endif
            </section>

            @if ($job->kind === \App\Enums\JobKind::Freelance && $match?->freelance_fit)
                @php($ff = $match->freelance_fit)
                <section class="card card-pad">
                    <h2 class="h-section">Freelance fit</h2>
                    <p class="mt-1 text-xs text-slate-500">Judged on the project itself, separately from employment. Nothing is assumed.</p>
                    <div class="mt-3 grid gap-4 sm:grid-cols-2 text-sm">
                        <div><p class="font-medium text-slate-700">Budget</p><p class="text-slate-600">{{ $ff['budget'] ?? 'Not stated' }}</p></div>
                        <div><p class="font-medium text-slate-700">Deadline / timeline</p><p class="text-slate-600">{{ $ff['deadline'] ?? 'Not stated' }}</p></div>
                        <div><p class="font-medium text-slate-700">Requested stack</p><p class="text-slate-600">{{ $ff['stack'] ? implode(', ', $ff['stack']) : 'Not stated' }}</p>
                            @if ($ff['stack_missing'])<p class="text-xs text-amber-700">Not on your profile: {{ implode(', ', $ff['stack_missing']) }}</p>@endif</div>
                        <div><p class="font-medium text-slate-700">Your relevant portfolio items</p>
                            @forelse ($ff['portfolio_items'] as $p)<p class="text-slate-600">• {{ $p }}</p>@empty<p class="text-slate-500">None found in your profile projects.</p>@endforelse</div>
                    </div>
                    @if ($ff['deliverables'])
                        <p class="mt-4 text-sm font-medium text-slate-700">Deliverables mentioned</p>
                        <ul class="list-disc pl-5 text-sm text-slate-600">@foreach ($ff['deliverables'] as $d)<li>{{ $d }}</li>@endforeach</ul>
                    @endif
                    @if ($ff['proposal_questions'])
                        <p class="mt-4 text-sm font-medium text-slate-700">Questions to answer in your proposal</p>
                        <ul class="list-disc pl-5 text-sm text-slate-600">@foreach ($ff['proposal_questions'] as $q)<li>{{ $q }}</li>@endforeach</ul>
                    @endif
                    @if ($ff['unknowns'])
                        <div class="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-inset ring-amber-200">
                            <p class="font-semibold">Unknowns</p>
                            <ul class="mt-1 list-disc pl-5">@foreach ($ff['unknowns'] as $u)<li>{{ $u }}</li>@endforeach</ul>
                        </div>
                    @endif
                </section>
            @endif

            @if ($job->is_manual)
                <section class="card card-pad" x-data="{ open: {{ $job->needs_details ? 'true' : 'false' }} }">
                    <div class="flex items-center justify-between">
                        <h2 class="h-section">Edit imported details</h2>
                        <button type="button" class="btn btn-ghost btn-sm" @click="open = !open" :aria-expanded="open.toString()">Toggle</button>
                    </div>
                    <form x-show="open" x-cloak method="POST" action="{{ route('jobs.details', $job) }}" class="mt-4 space-y-4">
                        @csrf @method('PUT')
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-field label="Title" name="title"><input id="f-title" name="title" class="input" value="{{ old('title', $job->title) }}" required></x-field>
                            <x-field label="Company" name="company"><input id="f-company" name="company" class="input" value="{{ old('company', $job->company) }}"></x-field>
                            <x-field label="Location" name="location" class="sm:col-span-2"><input id="f-location" name="location" class="input" value="{{ old('location', $job->location) }}"></x-field>
                        </div>
                        <x-field label="Description (paste the posting text)" name="description"><textarea id="f-description" name="description" rows="8" class="input">{{ old('description', $job->description) }}</textarea></x-field>
                        <button class="btn btn-primary">Save &amp; re-score</button>
                    </form>
                </section>
            @endif

            <section class="card card-pad">
                <h2 class="h-section">Description</h2>
                @if ($job->description)
                    <div class="prose-plain mt-3">{{ $job->description }}</div>
                @else
                    <p class="mt-3 text-sm text-slate-500">No description was provided by the source{{ $job->is_manual ? ' — add one above so it can be scored' : '' }}. Open the source website to read the posting.</p>
                @endif
                <p class="mt-4 text-xs text-slate-400">Shown as plain text exactly as stored. Open the source website for the official, formatted posting.</p>
            </section>
        </div>

        {{-- RIGHT: fit --}}
        <aside class="space-y-6">
            <section class="card card-pad" aria-labelledby="score-h">
                <div class="flex items-center gap-4">
                    <x-score :value="$match?->score" :tier="$tier->value" :size="72" />
                    <div>
                        <h2 id="score-h" class="text-lg font-bold text-slate-900">{{ $match ? $tier->label() : 'Not scored' }}</h2>
                        <p class="text-xs text-slate-500">{{ $match ? 'Prioritisation aid, not a prediction.' : ($profile ? 'Scoring runs automatically; use Re-score to refresh.' : 'Review & save your profile to score jobs.') }}</p>
                    </div>
                </div>

                <div class="mt-4 rounded-lg p-3 text-sm ring-1 ring-inset {{ ['emerald' => 'bg-emerald-50 text-emerald-900 ring-emerald-200', 'amber' => 'bg-amber-50 text-amber-900 ring-amber-200', 'rose' => 'bg-rose-50 text-rose-900 ring-rose-200'][$elig->tone()] }}">
                    <p class="font-semibold">{{ $elig->label() }}</p>
                    <p class="mt-0.5 text-xs">{{ $eligibility['note'] }}</p>
                    <p class="mt-1 text-[11px] opacity-75">Eligibility is a flag, not part of the score. Work authorisation and relocation are never assumed.</p>
                </div>

                @if ($match)
                    <h3 class="mt-5 text-sm font-semibold text-slate-900">Score breakdown</h3>
                    <ul class="mt-2 space-y-3">
                        @foreach ($match->factors as $key => $f)
                            <li>
                                <div class="flex items-baseline justify-between text-sm">
                                    <span class="font-medium text-slate-700">{{ $f['label'] }}</span>
                                    @if ($f['applicable'])
                                        <span class="tabular-nums text-slate-600">{{ rtrim(rtrim(number_format($f['points'], 1), '0'), '.') }} / {{ rtrim(rtrim(number_format($f['max'], 1), '0'), '.') }}</span>
                                    @else
                                        <span class="text-xs text-slate-400">not applicable</span>
                                    @endif
                                </div>
                                <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100">
                                    @if ($f['applicable'])<div class="h-full rounded-full {{ $tier === MatchTier::Strong ? 'bg-emerald-500' : 'bg-amber-500' }}" style="width: {{ round($f['pct'] * 100) }}%"></div>@endif
                                </div>
                                <p class="mt-1 text-xs text-slate-500">{{ $f['detail'] }}</p>
                            </li>
                        @endforeach
                    </ul>

                    <div class="mt-5 space-y-3 text-sm">
                        <div>
                            <p class="font-semibold text-slate-900">Matched skills</p>
                            <div class="mt-1 flex flex-wrap gap-1.5">@forelse ($match->matched_skills ?? [] as $s)<x-badge tone="emerald">{{ $s }}</x-badge>@empty<span class="text-slate-500">None found</span>@endforelse</div>
                        </div>
                        <div>
                            <p class="font-semibold text-slate-900">Missing requirements</p>
                            <div class="mt-1 flex flex-wrap gap-1.5">@forelse ($match->missing_skills ?? [] as $s)<x-badge tone="rose">{{ $s }}</x-badge>@empty<span class="text-slate-500">Nothing obviously missing</span>@endforelse</div>
                        </div>
                        <div>
                            <p class="font-semibold text-slate-900">Why this score</p>
                            <p class="mt-1 text-slate-600">{{ $match->rationale }}</p>
                        </div>
                    </div>

                    @php($ai = $match->ai_analysis)
                    @if ($ai)
                        <div class="mt-5 rounded-lg bg-slate-50 p-3 text-sm ring-1 ring-inset ring-slate-200">
                            <p class="flex items-center gap-2 font-semibold text-slate-900"><x-icon name="sparkles" class="h-4 w-4 text-amber-600" /> AI assessment <span class="text-xs font-normal text-slate-400">({{ $match->model_id }})</span></p>
                            @foreach ($ai['strengths'] as $st)
                                <div class="mt-2">
                                    <p class="font-medium text-slate-800">{{ $st['requirement'] }}</p>
                                    @foreach ($st['evidence'] as $ev)<p class="text-xs text-slate-500">↳ <span class="font-mono">{{ $ev['ref'] }}</span> {{ $ev['text'] }}</p>@endforeach
                                </div>
                            @endforeach
                            @if ($ai['gaps'])<p class="mt-2 text-xs text-slate-600"><span class="font-semibold">Gaps:</span> {{ implode('; ', $ai['gaps']) }}</p>@endif
                            <p class="mt-2 text-[11px] text-slate-400">Each point cites evidence from your profile; points without verifiable evidence were dropped.</p>
                        </div>
                    @else
                        <p class="mt-4 text-xs text-slate-500">
                            AI status: <strong>{{ \Illuminate\Support\Str::headline($match->ai_status) }}</strong>@if ($match->ai_note) — {{ $match->ai_note }}@endif
                        </p>
                    @endif
                    <p class="mt-3 text-[11px] text-slate-400">Scored {{ jp_ago($match->cached_at) }} against profile v{{ $match->profile_version }}.</p>
                @endif

                @if ($profile)
                    <form method="POST" action="{{ route('jobs.rescore', $job) }}" class="mt-4">@csrf
                        <button class="btn btn-secondary btn-sm"><x-icon name="refresh" class="h-4 w-4" /> Re-score</button>
                    </form>
                @endif
            </section>

            @if ($duplicates->isNotEmpty())
                <section class="card card-pad text-sm">
                    <h2 class="h-section">Also listed on</h2>
                    <ul class="mt-2 space-y-1">@foreach ($duplicates as $d)<li><a class="text-amber-700 hover:underline" href="{{ route('jobs.show', $d) }}">{{ $d->source->name }}</a></li>@endforeach</ul>
                </section>
            @endif
        </aside>
    </div>
</x-layouts.app>
