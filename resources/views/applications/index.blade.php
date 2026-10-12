<x-layouts.app title="Applications">
    <x-page-header title="Applications" subtitle="Every draft, approval and outcome in one pipeline. A job only counts as submitted after you (or an authorised integration) confirm it.">
        <x-slot name="actions">
            <a href="{{ route('jobs.index') }}" class="btn btn-secondary"><x-icon name="briefcase" class="h-4 w-4" /> Find jobs to apply for</a>
        </x-slot>
    </x-page-header>

    <form method="GET" class="card flex flex-wrap items-end gap-3 p-4" aria-label="Filter applications">
        <div class="min-w-48 flex-1"><label class="label" for="q">Search</label><input id="q" name="q" value="{{ $filters['q'] ?? '' }}" class="input" placeholder="Company or position"></div>
        <div><label class="label" for="status">Status</label>
            <select id="status" name="status" class="input"><option value="">All</option>@foreach ($statuses as $s)<option value="{{ $s->value }}" @selected(($filters['status'] ?? '') === $s->value)>{{ $s->label() }}</option>@endforeach</select></div>
        <div><label class="label" for="type">Type</label>
            <select id="type" name="type" class="input"><option value="">Any</option><option value="employment" @selected(($filters['type'] ?? '') === 'employment')>Employment</option><option value="freelance" @selected(($filters['type'] ?? '') === 'freelance')>Freelance</option></select></div>
        <div><label class="label" for="source">Source</label>
            <select id="source" name="source" class="input"><option value="">Any</option>@foreach ($sources as $s)<option value="{{ $s->id }}" @selected((int) ($filters['source'] ?? 0) === $s->id)>{{ $s->name }}</option>@endforeach</select></div>
        <div class="inline-flex rounded-lg ring-1 ring-inset ring-slate-300" role="group" aria-label="Layout">
            <button name="view" value="table" class="btn btn-sm rounded-r-none {{ $view === 'table' ? 'bg-slate-900 text-white' : 'bg-white text-slate-600' }}" data-no-lock>Table</button>
            <button name="view" value="board" class="btn btn-sm rounded-l-none {{ $view === 'board' ? 'bg-slate-900 text-white' : 'bg-white text-slate-600' }}" data-no-lock>Board</button>
        </div>
        <button class="btn btn-primary">Filter</button>
        <a href="{{ route('applications.index') }}" class="btn btn-ghost">Reset</a>
    </form>

    @if ($apps->isEmpty())
        <x-empty icon="document" title="No applications yet" text="Open a job and choose “Create draft”. The draft stays private and unsent until you approve it and apply on the employer's page.">
            <a href="{{ route('jobs.index') }}" class="btn btn-accent">Browse the job feed</a>
        </x-empty>
    @elseif ($view === 'board')
        <div class="grid gap-4 overflow-x-auto pb-2 md:grid-cols-3 xl:grid-cols-7">
            @foreach ($stages as $name => $statusValues)
                @php($col = $apps->filter(fn ($a) => in_array($a->status->value, $statusValues, true)))
                <section class="min-w-48 rounded-xl bg-slate-100/70 p-3" aria-label="{{ $name }}">
                    <h2 class="mb-2 flex justify-between text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $name }} <span class="tabular-nums">{{ $col->count() }}</span></h2>
                    <div class="space-y-2">
                        @foreach ($col as $a)
                            <a href="{{ route('applications.show', $a) }}" class="block rounded-lg border border-slate-200 bg-white p-3 text-sm shadow-sm transition hover:border-amber-300">
                                <p class="font-medium leading-snug text-slate-900">{{ $a->job->title }}</p>
                                <p class="truncate text-xs text-slate-500">{{ $a->job->company ?: '—' }}</p>
                                <p class="mt-1 text-[11px] text-slate-400">{{ $a->last_event_at ? jp_ago($a->last_event_at) : '' }}</p>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
    @else
        <div class="table-wrap">
            <table class="table-base">
                <thead><tr><th scope="col">Position</th><th scope="col">Status</th><th scope="col">Source</th><th scope="col">Applied</th><th scope="col">Last event</th><th scope="col">Next</th></tr></thead>
                <tbody>
                @foreach ($apps as $a)
                    <tr class="hover:bg-slate-50">
                        <td class="min-w-56">
                            <a href="{{ route('applications.show', $a) }}" class="font-medium text-slate-900 hover:text-amber-700 hover:underline">{{ $a->job->title }}</a>
                            <p class="text-xs text-slate-500">{{ $a->job->company ?: 'Company not stated' }}{{ $a->type === 'freelance' ? ' · freelance' : '' }}</p>
                        </td>
                        <td><x-badge :tone="$a->status->tone()" dot>{{ $a->status->label() }}</x-badge></td>
                        <td class="whitespace-nowrap text-slate-600">{{ $a->job->source->name }}</td>
                        <td class="whitespace-nowrap text-slate-600">{{ $a->submitted_at ? jp_date($a->submitted_at) : 'Not submitted' }}</td>
                        <td class="whitespace-nowrap text-slate-500">{{ $a->last_event_at ? jp_ago($a->last_event_at) : '—' }}</td>
                        <td class="text-slate-600">
                            @if ($a->next_task){{ $a->next_task }}@if ($a->next_task_at) <span class="text-xs text-slate-400">· {{ jp_date($a->next_task_at) }}</span>@endif
                            @elseif ($a->interviews->first())Interview {{ jp_dt($a->interviews->first()->when(), 'M j, g:i A') }}
                            @else<span class="text-slate-300">—</span>@endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-layouts.app>
