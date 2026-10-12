@php
    $maxFunnel = max(1, collect($funnel)->max('count'));
    $nothing = collect($funnel)->sum('count') === 0 && ! $skills;
@endphp
<x-layouts.app title="Insights">
    <x-page-header title="Insights" subtitle="What your search data says: where jobs come from, how far applications get, and which skills the market asks for that you don't list. All computed from your own data — no external services.">
        <x-slot name="actions">
            <nav class="inline-flex rounded-lg ring-1 ring-inset ring-slate-300" aria-label="Period">
                @foreach ([30 => '30 days', 90 => '90 days', 180 => '6 months', 365 => '1 year'] as $d => $label)
                    <a href="{{ route('insights.index', ['days' => $d]) }}" class="whitespace-nowrap px-3 py-1.5 text-sm font-medium first:rounded-l-lg last:rounded-r-lg {{ $days === $d ? 'bg-slate-900 text-white' : 'bg-white text-slate-600 hover:bg-slate-50' }}" @if ($days === $d) aria-current="true" @endif>{{ $label }}</a>
                @endforeach
            </nav>
        </x-slot>
    </x-page-header>

    @if ($nothing)
        <x-empty icon="trend" title="Nothing to analyse yet" text="Insights appear once jobs are collected and you start applying. Add sources, review your profile, and come back.">
            <a href="{{ route('sources.index') }}" class="btn btn-accent">Add job sources</a>
        </x-empty>
    @endif

    {{-- headline numbers --}}
    <section aria-label="Headlines" class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-stat label="Submitted" :value="$response['submitted']" icon="check-circle" tone="sky" :note="'last '.$days.' days'" />
        <x-stat label="Heard back" :value="$response['rate'] !== null ? $response['rate'].'%' : '—'" icon="mail" tone="violet" :note="$response['responded'].' of '.$response['submitted']" />
        <x-stat label="Days to first reply" :value="$response['median_days'] !== null ? $response['median_days'] : '—'" icon="clock" tone="amber" note="median" />
        <x-stat label="Still waiting" :value="$response['pending']" icon="calendar" tone="slate" note="no reply yet" />
    </section>

    {{-- funnel --}}
    <section class="card card-pad" aria-labelledby="fun-h">
        <h2 id="fun-h" class="h-section">Search funnel</h2>
        <p class="mt-1 text-xs text-slate-500">Each step shows how many made it from the previous one. "Heard back" counts rejections too — silence is the thing to improve.</p>
        <div class="mt-4 space-y-3">
            @foreach ($funnel as $row)
                <div class="grid grid-cols-[minmax(0,8rem)_1fr_auto] items-center gap-3 text-sm sm:grid-cols-[minmax(0,10rem)_1fr_auto]" title="{{ $row['note'] }}">
                    <div class="font-medium text-slate-700">{{ $row['label'] }}</div>
                    <div class="h-6 overflow-hidden rounded-md bg-slate-100" role="img" aria-label="{{ $row['label'] }}: {{ $row['count'] }}">
                        @unless ($row['unavailable'])<div class="flex h-full items-center rounded-md bg-gradient-to-r from-amber-400 to-amber-500 px-2 text-xs font-semibold text-slate-900" style="width: {{ $row['count'] > 0 ? max(2, round($row['count'] / $maxFunnel * 100)) : 0 }}%"></div>@endunless
                    </div>
                    <div class="min-w-[6rem] text-right tabular-nums">
                        @if ($row['unavailable'])<span class="text-xs text-slate-400">needs a profile</span>
                        @else<span class="font-semibold text-slate-900">{{ $row['count'] }}</span>@if ($row['pct'] !== null)<span class="ml-1 text-xs text-slate-400">{{ $row['pct'] }}%</span>@endif @endif
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    <div class="grid gap-6 lg:grid-cols-2">
        @if ($histogram)
            @php($maxH = max(1, collect($histogram)->max('count')))
            <section class="card card-pad" aria-labelledby="hist-h">
                <h2 id="hist-h" class="h-section">Score distribution</h2>
                <p class="mt-1 text-xs text-slate-500">All active jobs against your current profile. Thresholds: potential ≥ {{ settings()->potentialThreshold() }}, strong ≥ {{ settings()->strongThreshold() }}.</p>
                <div class="mt-4 flex h-40 items-end gap-1.5" role="group" aria-label="Number of jobs per score range">
                    @foreach ($histogram as $i => $b)
                        <div class="flex flex-1 flex-col items-center justify-end gap-1" title="{{ $b['label'] }}: {{ $b['count'] }} job(s)">
                            <span class="text-[11px] tabular-nums text-slate-500">{{ $b['count'] ?: '' }}</span>
                            <div class="w-full rounded-t {{ $i * 10 >= settings()->strongThreshold() ? 'bg-emerald-500' : ($i * 10 >= settings()->potentialThreshold() - 9 ? 'bg-amber-500' : 'bg-slate-300') }}" style="height: {{ max($b['count'] ? 3 : 0, round($b['count'] / $maxH * 100)) }}%"></div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-1 flex gap-1.5 text-[10px] text-slate-400">@foreach ($histogram as $b)<span class="flex-1 text-center">{{ \Illuminate\Support\Str::before($b['label'], '–') }}</span>@endforeach</div>
            </section>
        @endif

        @php($maxW = max(1, collect($weekly)->max(fn ($w) => max($w['jobs'], $w['submitted']))))
        <section class="card card-pad" aria-labelledby="wk-h">
            <h2 id="wk-h" class="h-section">Last 8 weeks</h2>
            <p class="mt-1 text-xs text-slate-500"><span class="mr-3"><span class="mr-1 inline-block h-2 w-2 rounded-full bg-slate-300"></span>new jobs</span><span><span class="mr-1 inline-block h-2 w-2 rounded-full bg-sky-500"></span>submitted by you</span></p>
            <div class="mt-4 flex h-40 items-end gap-2" role="group" aria-label="Jobs found and applications submitted per week">
                @foreach ($weekly as $w)
                    <div class="flex flex-1 flex-col items-center justify-end gap-1" title="Week of {{ $w['label'] }}: {{ $w['jobs'] }} new job(s), {{ $w['submitted'] }} submitted">
                        <div class="flex h-full w-full items-end justify-center gap-0.5">
                            <div class="w-1/2 rounded-t bg-slate-300" style="height: {{ max($w['jobs'] ? 3 : 0, round($w['jobs'] / $maxW * 100)) }}%"></div>
                            <div class="w-1/2 rounded-t bg-sky-500" style="height: {{ max($w['submitted'] ? 3 : 0, round($w['submitted'] / $maxW * 100)) }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="mt-1 flex gap-2 text-[10px] text-slate-400">@foreach ($weekly as $w)<span class="flex-1 text-center">{{ $w['label'] }}</span>@endforeach</div>
        </section>
    </div>

    {{-- sources --}}
    <section aria-labelledby="src-h">
        <h2 id="src-h" class="h-section mb-3">Which sources pay off</h2>
        @if (empty($bySource))
            <p class="text-sm text-slate-500">No source activity in this period.</p>
        @else
            <div class="table-wrap">
                <table class="table-base">
                    <thead><tr><th scope="col">Source</th><th scope="col">Jobs found</th><th scope="col">Avg score</th><th scope="col">Good matches</th><th scope="col">Applications</th><th scope="col">Submitted</th><th scope="col">Heard back</th></tr></thead>
                    <tbody>
                    @foreach ($bySource as $r)
                        <tr>
                            <td class="min-w-40"><a class="font-medium text-slate-900 hover:text-amber-700 hover:underline" href="{{ route('jobs.index', ['source' => $r['id'], 'show_low' => 1]) }}">{{ $r['name'] }}</a><p class="text-xs text-slate-400">{{ $r['type'] }}</p></td>
                            <td class="tabular-nums">{{ $r['jobs'] }}</td>
                            <td class="tabular-nums">{{ $r['avg_score'] ?? '—' }}</td>
                            <td class="tabular-nums font-semibold text-emerald-700">{{ $r['good'] }}</td>
                            <td class="tabular-nums">{{ $r['applications'] }}</td>
                            <td class="tabular-nums">{{ $r['submitted'] }}</td>
                            <td class="tabular-nums">{{ $r['responded'] }}@if ($r['rate'] !== null) <span class="text-xs text-slate-400">({{ $r['rate'] }}%)</span>@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-xs text-slate-400">"Good matches" = strong or potential and not excluded. Small samples are noisy — judge a source after a few applications.</p>
        @endif
    </section>

    {{-- skills --}}
    @if ($skills)
        <div class="grid gap-6 lg:grid-cols-2">
            <section class="card card-pad" aria-labelledby="sk-h">
                <div class="flex items-start justify-between gap-2">
                    <div><h2 id="sk-h" class="h-section">Skills the market asks for</h2>
                        <p class="mt-1 text-xs text-slate-500">Top 20 across {{ $skills['jobs'] }} active postings. <span class="text-emerald-700">Green</span> = on your profile, <span class="text-rose-600">red</span> = not.</p></div>
                    @if ($skills['coverage'] !== null)<x-badge :tone="$skills['coverage'] >= 60 ? 'emerald' : 'amber'">You cover {{ $skills['coverage'] }}%</x-badge>@endif
                </div>
                <div class="mt-4 space-y-2.5">
                    @forelse ($skills['demand'] as $d)
                        <x-bar :label="$d['skill'].($d['have'] ? ' ✓' : '')" :value="$d['pct']" :max="max(1, $skills['demand'][0]['pct'])" suffix="%" :tone="$d['have'] ? 'emerald' : 'rose'" :note="$d['count'].' jobs'" />
                    @empty
                        <p class="text-sm text-slate-500">No skills detected in collected postings yet.</p>
                    @endforelse
                </div>
            </section>

            <section class="card card-pad" aria-labelledby="gap-h">
                <h2 id="gap-h" class="h-section">Your biggest skill gaps</h2>
                <p class="mt-1 text-xs text-slate-500">Skills you don't list that appear most in jobs that are <em>otherwise good matches</em> and open to your country — the highest-payoff things to learn (or add to your profile if you already have them).</p>
                @forelse ($skills['gaps'] as $g)
                    <div class="mt-3 flex items-center justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200">
                        <div><span class="font-semibold text-slate-900">{{ $g['skill'] }}</span> @if ($g['category'])<span class="ml-1 text-xs text-slate-400">{{ $g['category'] }}</span>@endif</div>
                        <span class="tabular-nums text-slate-600">in {{ $g['count'] }} good match(es)</span>
                    </div>
                @empty
                    <p class="mt-3 text-sm text-slate-500">No gaps to report — either there are no good matches yet, or none of them name a skill you lack.</p>
                @endforelse
                <p class="mt-4 text-xs text-slate-400">Only add a skill to your profile if it's true — drafts quote your profile verbatim.</p>
            </section>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        @if ($salary)
            <section class="card card-pad" aria-labelledby="sal-h">
                <h2 id="sal-h" class="h-section">Stated salaries</h2>
                <p class="mt-1 text-xs text-slate-500">Top of the yearly range in {{ $salary['n'] }} active postings that state one, in {{ $salary['currency'] }} (other currencies are never converted). Many postings state none, so treat this as a rough guide.</p>
                <dl class="mt-4 grid grid-cols-3 gap-3 text-center">
                    <div class="rounded-lg bg-slate-50 p-3 ring-1 ring-inset ring-slate-200"><dt class="text-xs text-slate-500">Lower quartile</dt><dd class="text-lg font-bold tabular-nums text-slate-900">{{ number_format($salary['p25']) }}</dd></div>
                    <div class="rounded-lg bg-amber-50 p-3 ring-1 ring-inset ring-amber-200"><dt class="text-xs text-amber-800">Median</dt><dd class="text-lg font-bold tabular-nums text-slate-900">{{ number_format($salary['median']) }}</dd></div>
                    <div class="rounded-lg bg-slate-50 p-3 ring-1 ring-inset ring-slate-200"><dt class="text-xs text-slate-500">Upper quartile</dt><dd class="text-lg font-bold tabular-nums text-slate-900">{{ number_format($salary['p75']) }}</dd></div>
                </dl>
            </section>
        @endif
        @if ($eligibility)
            @php($eligTotal = max(1, array_sum($eligibility)))
            <section class="card card-pad" aria-labelledby="el-h">
                <h2 id="el-h" class="h-section">How open the market is to you</h2>
                <p class="mt-1 text-xs text-slate-500">Remote eligibility across scored, non-excluded active jobs. {{ blank(settings()->get('home_country')) ? 'Set your country in Settings for a real answer.' : '' }}</p>
                <div class="mt-4 space-y-2.5">
                    <x-bar label="Open to your location" :value="$eligibility['eligible']" :max="$eligTotal" tone="emerald" />
                    <x-bar label="Eligibility unclear" :value="$eligibility['unclear']" :max="$eligTotal" tone="amber" />
                    <x-bar label="Location restricted" :value="$eligibility['restricted']" :max="$eligTotal" tone="rose" />
                </div>
            </section>
        @endif
    </div>
</x-layouts.app>
