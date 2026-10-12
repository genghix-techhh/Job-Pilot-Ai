<x-layouts.app title="Dashboard">
    <x-page-header title="Dashboard" subtitle="Your private job-hunting command centre. Discovery and scoring are automatic; applying always needs your approval.">
        <x-slot name="actions">
            <a href="{{ route('jobs.create') }}" class="btn btn-secondary"><x-icon name="plus" class="h-4 w-4" /> Add a job link</a>
            <form method="POST" action="{{ route('sources.sync-all') }}">@csrf
                <button class="btn btn-accent"><x-icon name="refresh" class="h-4 w-4" /> Run discovery now</button>
            </form>
        </x-slot>
    </x-page-header>

    {{-- Warnings that need attention --}}
    @if ($consentStale)
        <div class="flex flex-wrap items-center gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
            <x-icon name="warning" class="h-5 w-5 text-amber-600" />
            <p class="flex-1">The privacy terms changed since you agreed. AI analysis is paused until you review them.</p>
            <a href="{{ route('privacy.consent') }}" class="btn btn-accent btn-sm">Review &amp; decide</a>
        </div>
    @endif
    @if ($queue['stalled'])
        <div class="flex flex-wrap items-center gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
            <x-icon name="warning" class="h-5 w-5 text-amber-600" />
            <p class="flex-1"><strong>{{ $queue['pending'] }} queued task(s) are waiting</strong> (oldest {{ $queue['oldest_minutes'] }} min) — no queue worker seems to be running. Start one with <code class="rounded bg-white/70 px-1">php artisan queue:work</code> or make sure the cron scheduler is set up.</p>
            <a href="{{ route('activity.index') }}" class="btn btn-secondary btn-sm">Diagnostics</a>
        </div>
    @endif

    @unless ($checklistDone)
        <section class="card card-pad" aria-labelledby="start-h">
            <h2 id="start-h" class="h-section">Get started</h2>
            <p class="mt-1 text-sm text-slate-600">Nothing here is pre-filled with fake jobs. Do these steps and real openings from the sources you choose will appear.</p>
            <ol class="mt-4 grid gap-2 sm:grid-cols-2">
                @foreach ($checklist as $i => $step)
                    <li>
                        <a href="{{ $step['url'] }}" class="flex items-center gap-3 rounded-lg border px-3 py-2.5 text-sm transition {{ $step['done'] ? 'border-emerald-200 bg-emerald-50/60 text-emerald-900' : 'border-slate-200 bg-white text-slate-800 hover:border-amber-300' }}">
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-bold {{ $step['done'] ? 'bg-emerald-500 text-white' : 'bg-slate-100 text-slate-600' }}">
                                @if ($step['done'])<x-icon name="check" class="h-4 w-4" />@else{{ $i + 1 }}@endif
                            </span>
                            <span class="{{ $step['done'] ? 'line-through decoration-emerald-400' : '' }}">{{ $step['label'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ol>
        </section>
    @endunless

    {{-- KPI row --}}
    <section aria-label="Overview" class="grid grid-cols-2 gap-3 md:grid-cols-3 2xl:grid-cols-6">
        <x-stat label="New this week" :value="$stats['new']" icon="briefcase" :href="route('jobs.index', ['posted' => 7])" tone="slate" />
        <x-stat label="Strong matches" :value="$stats['strong'] ?? '–'" icon="sparkles" :href="route('jobs.index', ['min_score' => settings()->strongThreshold()])" tone="emerald" :note="$profile ? null : 'Needs a reviewed profile'" />
        <x-stat label="Drafts to review" :value="$stats['drafts']" icon="pencil" :href="route('applications.index')" tone="amber" />
        <x-stat label="Submitted" :value="$stats['submitted']" icon="check-circle" :href="route('applications.index', ['status' => 'submitted'])" tone="sky" />
        <x-stat label="Responses" :value="$stats['responses']" icon="mail" :href="route('applications.index')" tone="violet" :note="$needsReview ? $needsReview.' e-mail(s) to review' : null" />
        <x-stat label="Upcoming interviews" :value="$stats['interviews']" icon="calendar" :href="route('interviews.index')" tone="violet" />
    </section>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{-- Best matches --}}
            <section aria-labelledby="top-h">
                <div class="mb-3 flex items-center justify-between">
                    <h2 id="top-h" class="h-section">Best matches right now</h2>
                    <a href="{{ route('jobs.index') }}" class="text-sm font-medium text-amber-700 hover:underline">Open job feed →</a>
                </div>
                @if (! $profile)
                    <x-empty icon="user" title="Review your profile to see scores" text="Upload your CV, check what was parsed, and save. Until then jobs are listed but not ranked.">
                        <a href="{{ route('profile.edit') }}" class="btn btn-accent">Go to CV &amp; Profile</a>
                    </x-empty>
                @elseif ($topMatches->isEmpty())
                    <x-empty icon="briefcase" title="No strong or potential matches yet" text="Add sources (Greenhouse boards, Lever sites, Remote OK or RSS feeds) and run discovery. Matches show up here as soon as they are scored.">
                        <a href="{{ route('sources.index') }}" class="btn btn-accent">Add job sources</a>
                    </x-empty>
                @else
                    <div class="card divide-y divide-slate-100">
                        @foreach ($topMatches as $m)
                            <a href="{{ route('jobs.show', $m->job) }}" class="flex items-center gap-4 p-4 transition hover:bg-slate-50">
                                <x-score :value="$m->score" :tier="$m->tier->value" />
                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-semibold text-slate-900">{{ $m->job->title }}</p>
                                    <p class="truncate text-sm text-slate-500">{{ $m->job->company ?: 'Company not stated' }} · {{ $m->job->source->name }}</p>
                                </div>
                                <div class="hidden flex-col items-end gap-1 sm:flex">
                                    <x-badge :tone="$m->tier->tone()" dot>{{ $m->tier->label() }}</x-badge>
                                    <x-badge :tone="$m->eligibility_status->tone()">{{ $m->eligibility_status->label() }}</x-badge>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @endif
            </section>

            @if ($followUps->isNotEmpty())
                <section aria-labelledby="fu-h">
                    <h2 id="fu-h" class="h-section mb-3">Time to follow up</h2>
                    <ul class="card divide-y divide-slate-100">
                        @foreach ($followUps as $a)
                            <li class="flex items-center gap-3 p-4">
                                <x-icon name="clock" class="h-5 w-5 text-amber-500" />
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium text-slate-900">{{ $a->job->title }} <span class="font-normal text-slate-500">· {{ $a->job->company ?: 'company not stated' }}</span></p>
                                    <p class="text-xs text-slate-500">Submitted {{ jp_date($a->submitted_at) }} — no reply for {{ $followUpService->daysWaiting($a) }} days</p>
                                </div>
                                <a href="{{ route('applications.show', $a) }}" class="btn btn-secondary btn-sm">Follow-up message</a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            {{-- Upcoming --}}
            <section aria-labelledby="up-h">
                <div class="mb-3 flex items-center justify-between">
                    <h2 id="up-h" class="h-section">Interviews &amp; deadlines</h2>
                    <a href="{{ route('interviews.index') }}" class="text-sm font-medium text-amber-700 hover:underline">All →</a>
                </div>
                @if ($upcoming->isEmpty())
                    <x-empty icon="calendar" title="Nothing scheduled" text="Interviews and assessments from recruiter e-mails (when Gmail is connected) or entered by hand will appear here." />
                @else
                    <ul class="card divide-y divide-slate-100">
                        @foreach ($upcoming as $i)
                            <li class="flex items-center gap-3 p-4">
                                <x-icon name="clock" class="h-5 w-5 text-slate-400" />
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium text-slate-900">{{ $i->title }}</p>
                                    <p class="text-xs text-slate-500">{{ jp_dt($i->when(), 'D, M j · g:i A') }} <span class="text-slate-400">({{ user_tz() }})</span></p>
                                </div>
                                <x-badge :tone="$i->isConfirmed() ? 'emerald' : 'amber'">{{ ucfirst($i->state) }}</x-badge>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- Recent activity --}}
            <section aria-labelledby="act-h">
                <h2 id="act-h" class="h-section mb-3">Recent activity</h2>
                @if ($events->isEmpty())
                    <x-empty icon="clock" title="No activity yet" text="Drafts, approvals, submissions and recruiter replies are logged here." />
                @else
                    <ul class="card divide-y divide-slate-100">
                        @foreach ($events as $e)
                            <li class="flex items-start gap-3 p-3.5 text-sm">
                                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-amber-400"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-slate-800">
                                        <span class="font-medium">{{ \Illuminate\Support\Str::headline($e->type) }}</span>
                                        @if ($e->to_status) → {{ \App\Enums\ApplicationStatus::tryFrom($e->to_status)?->label() }} @endif
                                        <a class="text-amber-700 hover:underline" href="{{ route('applications.show', $e->application) }}">· {{ $e->application->job->title }}</a>
                                    </p>
                                    <p class="text-xs text-slate-400">{{ jp_dt($e->occurred_at) }} · {{ $e->actor }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>

        {{-- Right column: health --}}
        <div class="space-y-6">
            <section aria-labelledby="ai-h" class="card card-pad">
                <div class="flex items-center justify-between">
                    <h2 id="ai-h" class="h-section">Gemini (free tier)</h2>
                    <x-badge :tone="$ai['available'] ? 'emerald' : 'amber'" dot>{{ $ai['available'] ? 'Ready' : \Illuminate\Support\Str::headline($ai['state']) }}</x-badge>
                </div>
                <p class="mt-1 text-xs text-slate-500">Model <code>{{ $ai['model'] }}</code></p>
                <div class="mt-4">
                    <div class="flex justify-between text-xs text-slate-500"><span>Local daily budget</span><span class="tabular-nums">{{ $ai['calls_today'] }} / {{ $ai['limit'] }} calls</span></div>
                    <div class="mt-1 h-2 overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $ai['limit'] }}" aria-valuenow="{{ $ai['calls_today'] }}">
                        <div class="h-full rounded-full bg-amber-500" style="width: {{ $ai['limit'] ? min(100, round($ai['calls_today'] / $ai['limit'] * 100)) : 100 }}%"></div>
                    </div>
                </div>
                @unless ($ai['available'])
                    <p class="mt-3 text-sm text-slate-600">{{ $ai['message'] }}</p>
                @endunless
                @if ($ai['paused_until'])
                    <p class="mt-2 text-xs text-amber-700">Paused until {{ jp_dt($ai['paused_until']) }} ({{ $ai['pause_reason'] }}). Queued work resumes automatically.</p>
                @endif
                @if ($ai['recent_errors']->isNotEmpty())
                    <details class="mt-3 text-xs">
                        <summary class="cursor-pointer font-medium text-slate-600">Recent AI problems ({{ $ai['recent_errors']->count() }})</summary>
                        <ul class="mt-2 space-y-1 text-slate-500">
                            @foreach ($ai['recent_errors'] as $log)
                                <li>{{ jp_dt($log->created_at, 'M j, g:i A') }} · {{ $log->task }} · {{ $log->error_code ?: $log->status }}</li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            </section>

            <section aria-labelledby="src-h" class="card card-pad">
                <div class="flex items-center justify-between">
                    <h2 id="src-h" class="h-section">Source health</h2>
                    <a href="{{ route('sources.index') }}" class="text-sm font-medium text-amber-700 hover:underline">Manage</a>
                </div>
                @php($real = $sources->filter(fn ($s) => $s->type->isSyncable()))
                @if ($real->isEmpty())
                    <p class="mt-3 text-sm text-slate-500">No sources yet. Add a Greenhouse board, Lever site, Remote OK or an RSS feed.</p>
                @else
                    <ul class="mt-3 space-y-2.5">
                        @foreach ($real as $s)
                            @php($tone = ['ok' => 'emerald', 'error' => 'rose', 'unconfigured' => 'amber', 'never' => 'slate', 'disabled' => 'slate'][$s->health_status] ?? 'slate')
                            <li class="flex items-start justify-between gap-2 text-sm">
                                <div class="min-w-0">
                                    <p class="truncate font-medium text-slate-800">{{ $s->name }}</p>
                                    <p class="text-xs text-slate-400">{{ $s->last_synced_at ? 'synced '.jp_ago($s->last_synced_at) : 'not synced yet' }}</p>
                                    @if ($s->health_status === 'error' && $s->last_error)<p class="mt-0.5 text-xs text-rose-600">{{ \Illuminate\Support\Str::limit($s->last_error, 90) }}</p>@endif
                                </div>
                                <x-badge :tone="$tone" dot>{{ \Illuminate\Support\Str::headline($s->health_status) }}</x-badge>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section aria-labelledby="gm-h" class="card card-pad">
                <div class="flex items-center justify-between">
                    <h2 id="gm-h" class="h-section">Recruiter inbox</h2>
                    <x-badge :tone="$gmailConnected ? 'emerald' : 'slate'" dot>{{ $gmailConnected ? 'Connected' : 'Not connected' }}</x-badge>
                </div>
                <p class="mt-2 text-sm text-slate-600">
                    @if ($gmailConnected) {{ $needsReview }} message(s) need your review.
                    @elseif ($gmailConfigured) Connect Gmail (read-only) to detect replies, interviews and assessments.
                    @else Optional. Add Google OAuth credentials to enable it — the rest of the app works without. @endif
                </p>
                <a href="{{ route('inbox.index') }}" class="mt-3 inline-flex text-sm font-medium text-amber-700 hover:underline">Open inbox monitoring →</a>
            </section>
        </div>
    </div>
</x-layouts.app>
