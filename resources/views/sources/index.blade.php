@php($tones = ['ok' => 'emerald', 'error' => 'rose', 'unconfigured' => 'amber', 'never' => 'slate', 'disabled' => 'slate'])
<x-layouts.app title="Sources">
    <x-page-header title="Sources" subtitle="JobPilot only reads places that allow it. Each source is fetched politely, cached, and isolated: one failing source never stops the others.">
        <x-slot name="actions">
            <form method="POST" action="{{ route('sources.sync-all') }}">@csrf<button class="btn btn-accent"><x-icon name="refresh" class="h-4 w-4" /> Run discovery now</button></form>
        </x-slot>
    </x-page-header>

    <div class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900">
        <p class="font-semibold">What is — and isn't — automated</p>
        <p class="mt-1">Greenhouse and Lever expose each company's public openings, so you add the companies you care about (there is no global search). LinkedIn and Indeed are <strong>intentionally not scraped</strong> and Upwork/Fiverr are not automated; use “Add a job link” for those.</p>
    </div>

    {{-- Existing --}}
    @if ($sources->where('type.value', '!=', 'manual')->isEmpty() && $sources->isEmpty())
        <x-empty icon="globe" title="No sources yet" text="Add your first source below. Try the Remote OK feed for a quick start." />
    @endif
    <div class="grid gap-4 lg:grid-cols-2">
        @foreach ($sources as $s)
            @php($run = $lastRuns[$s->id] ?? null)
            <article class="card card-pad">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-xs font-medium uppercase tracking-wide text-slate-400">{{ $s->type->label() }}</p>
                        <h3 class="truncate font-semibold text-slate-900">{{ $s->name }}</h3>
                        @if ($s->target())<p class="truncate text-xs text-slate-500">{{ $s->target() }}</p>@endif
                    </div>
                    <x-badge :tone="$tones[$s->health_status] ?? 'slate'" dot>{{ \Illuminate\Support\Str::headline($s->health_status) }}</x-badge>
                </div>
                <dl class="mt-3 grid grid-cols-3 gap-2 text-center text-xs">
                    <div class="rounded-lg bg-slate-50 p-2"><dt class="text-slate-500">Jobs stored</dt><dd class="text-lg font-bold tabular-nums text-slate-900">{{ $s->jobs_count }}</dd></div>
                    <div class="rounded-lg bg-slate-50 p-2"><dt class="text-slate-500">Last sync</dt><dd class="font-semibold text-slate-800">{{ $s->last_synced_at ? jp_ago($s->last_synced_at) : 'never' }}</dd></div>
                    <div class="rounded-lg bg-slate-50 p-2"><dt class="text-slate-500">Next</dt><dd class="font-semibold text-slate-800">{{ $s->enabled && $s->type->isSyncable() ? ($s->next_sync_at ? ($s->next_sync_at->isPast() ? 'due' : $s->next_sync_at->diffForHumans()) : 'due') : '—' }}</dd></div>
                </dl>
                @if ($run && $run->status === 'ok')<p class="mt-2 text-xs text-slate-500">Last run: {{ $run->fetched }} fetched · {{ $run->created }} new · {{ $run->updated }} updated</p>@endif
                @if ($s->last_error)<p class="mt-2 rounded-lg bg-rose-50 p-2 text-xs text-rose-800 ring-1 ring-inset ring-rose-200">{{ $s->last_error }}@if ($s->consecutive_failures > 1) ({{ $s->consecutive_failures }} failures in a row)@endif</p>@endif
                @if ($s->type === \App\Enums\SourceType::Adzuna && isset($s->last_stats['requests_today']))<p class="mt-2 text-xs text-slate-500">This app has made {{ $s->last_stats['requests_today'] }} Adzuna request(s) today. Check your Adzuna dashboard for your real quota.</p>@endif
                @if ($s->type === \App\Enums\SourceType::RemoteOk)<p class="mt-2 text-xs text-slate-500">Listings link back to Remote OK, as their terms require. Fetched at most every 2 hours.</p>@endif

                @if ($s->type->isSyncable())
                    <div class="mt-4 flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('sources.sync', $s) }}">@csrf<button class="btn btn-secondary btn-sm"><x-icon name="play" class="h-4 w-4" /> Sync now</button></form>
                        <form method="POST" action="{{ route('sources.test', $s) }}">@csrf<button class="btn btn-secondary btn-sm">Test</button></form>
                        <form method="POST" action="{{ route('sources.update', $s) }}">@csrf @method('PATCH')<input type="hidden" name="enabled" value="{{ $s->enabled ? 0 : 1 }}"><button class="btn btn-ghost btn-sm">{{ $s->enabled ? 'Disable' : 'Enable' }}</button></form>
                        <form method="POST" action="{{ route('sources.destroy', $s) }}" x-data="confirmSubmit('Delete this source and all jobs fetched from it?')" @submit="check($event)">@csrf @method('DELETE')<button class="btn btn-ghost btn-sm text-rose-600" aria-label="Delete {{ $s->name }}"><x-icon name="trash" class="h-4 w-4" /></button></form>
                    </div>
                @else
                    <p class="mt-3 text-xs text-slate-500">Jobs you paste in with “Add a job link” are kept here.</p>
                @endif
            </article>
        @endforeach
    </div>

    {{-- Add --}}
    <section class="card card-pad" x-data="{ type: '{{ old('type', 'greenhouse') }}' }" aria-labelledby="add-h">
        <h2 id="add-h" class="h-section">Add a source</h2>
        <form method="POST" action="{{ route('sources.store') }}" class="mt-4 space-y-4" novalidate>
            @csrf
            <div>
                <label class="label" for="type">Type</label>
                <select id="type" name="type" class="input sm:!w-80" x-model="type">
                    @foreach ($types as $t)<option value="{{ $t->value }}">{{ $t->label() }}</option>@endforeach
                </select>
                @foreach ($types as $t)<p class="hint" x-show="type === '{{ $t->value }}'" x-cloak>{{ $t->description() }}</p>@endforeach
            </div>

            <div x-show="type === 'greenhouse'" x-cloak>
                <x-field label="Company board token" name="board_token" hint="From boards.greenhouse.io/<token> or job-boards.greenhouse.io/<token>.">
                    <input id="f-board_token" name="board_token" value="{{ old('board_token') }}" class="input sm:!w-80" placeholder="e.g. stripe" :disabled="type !== 'greenhouse'">
                </x-field>
            </div>

            <div x-show="type === 'lever'" x-cloak class="grid gap-4 sm:grid-cols-2">
                <x-field label="Lever site name" name="site" hint="From jobs.lever.co/<site>.">
                    <input id="f-site" name="site" value="{{ old('site') }}" class="input" placeholder="e.g. netflix" :disabled="type !== 'lever'">
                </x-field>
                <x-field label="Region" name="region">
                    <select id="f-region" name="region" class="input" :disabled="type !== 'lever'"><option value="global">Global (api.lever.co)</option><option value="eu" @selected(old('region') === 'eu')>EU (api.eu.lever.co)</option></select>
                </x-field>
            </div>

            <div x-show="type === 'remoteok'" x-cloak>
                <x-field label="Keywords (optional, comma separated)" name="keywords" hint="Keep only jobs whose title or tags contain one of these, e.g. php, laravel, react, ai. Leave empty to keep everything.">
                    <input id="f-keywords" name="keywords" value="{{ old('keywords') }}" class="input" :disabled="type !== 'remoteok'">
                </x-field>
            </div>

            <div x-show="type === 'rss'" x-cloak class="grid gap-4 sm:grid-cols-2">
                <x-field label="Feed URL" name="url" class="sm:col-span-2" hint="Public http(s) feeds only. Local, private and internal addresses are refused.">
                    <input id="f-url" type="url" name="url" value="{{ old('url') }}" class="input" placeholder="https://example.com/jobs.rss" :disabled="type !== 'rss'">
                </x-field>
                <x-field label="Feed contains" name="kind"><select id="f-kind" name="kind" class="input" :disabled="type !== 'rss'"><option value="employment">Jobs (employment)</option><option value="freelance" @selected(old('kind') === 'freelance')>Freelance projects</option></select></x-field>
                <x-field label="Default company (optional)" name="default_company" hint="Used when the feed doesn't name an employer."><input id="f-default_company" name="default_company" value="{{ old('default_company') }}" class="input" :disabled="type !== 'rss'"></x-field>
            </div>

            <div x-show="type === 'adzuna'" x-cloak class="space-y-4">
                <div class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-inset ring-amber-200">Disabled until you add your own Adzuna credentials and confirm your use is permitted. Adzuna returns short snippets and a redirect link; no free quota is assumed — check your Adzuna dashboard.</div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Country" name="country"><select id="f-country" name="country" class="input" :disabled="type !== 'adzuna'">@foreach ($adzunaCountries as $c)<option value="{{ $c }}" @selected(old('country') === $c)>{{ strtoupper($c) }}</option>@endforeach</select></x-field>
                    <x-field label="Search keywords" name="what"><input id="f-what" name="what" value="{{ old('what') }}" class="input" placeholder="laravel developer" :disabled="type !== 'adzuna'"></x-field>
                    <x-field label="Location (optional)" name="where"><input id="f-where" name="where" value="{{ old('where') }}" class="input" :disabled="type !== 'adzuna'"></x-field>
                    <x-field label="Pages per sync (1–3)" name="max_pages"><input id="f-max_pages" type="number" min="1" max="3" name="max_pages" value="{{ old('max_pages', 1) }}" class="input" :disabled="type !== 'adzuna'"></x-field>
                    <x-field label="App ID" name="app_id" hint="{{ $adzunaEnv ? 'Leave empty to use ADZUNA_APP_ID from .env.' : 'Stored encrypted.' }}"><input id="f-app_id" name="app_id" autocomplete="off" class="input" :disabled="type !== 'adzuna'"></x-field>
                    <x-field label="App key" name="app_key"><input id="f-app_key" type="password" name="app_key" autocomplete="off" class="input" :disabled="type !== 'adzuna'"></x-field>
                </div>
                <label class="flex gap-2 text-sm text-slate-700"><input type="checkbox" class="check mt-0.5" name="remote_only" value="1" checked :disabled="type !== 'adzuna'"> Add “remote” to the search</label>
                <label class="flex gap-2 text-sm text-slate-700"><input type="checkbox" class="check mt-0.5" name="usage_confirmed" value="1" :disabled="type !== 'adzuna'"> I checked Adzuna's terms and quota and my intended use is permitted.</label>
                @error('usage_confirmed')<p class="error">{{ $message }}</p>@enderror
            </div>

            <x-field label="Display name (optional)" name="name"><input id="f-name" name="name" value="{{ old('name') }}" class="input sm:!w-80" maxlength="120"></x-field>
            <button class="btn btn-primary">Add source &amp; fetch</button>
        </form>
    </section>
</x-layouts.app>
