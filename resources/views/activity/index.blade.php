@php
    $ok = fn ($b) => $b ? 'text-emerald-700' : 'text-amber-700';
    $tone = ['ok' => 'emerald', 'sent' => 'emerald', 'pending' => 'amber', 'running' => 'sky', 'error' => 'rose', 'failed' => 'rose', 'invalid' => 'rose', 'rate_limited' => 'amber', 'blocked' => 'rose'];
@endphp
<x-layouts.app title="Activity & Diagnostics">
    <x-page-header title="Activity &amp; Diagnostics" subtitle="Safe health information — secrets and prompts are never shown or logged." />

    <section class="grid gap-4 md:grid-cols-3" aria-label="Runtime health">
        <div class="card card-pad text-sm">
            <h2 class="h-section">Queue &amp; scheduler</h2>
            <p class="mt-2">Queue driver: <strong>{{ $queue['driver'] }}</strong></p>
            <p>Waiting: <strong>{{ $queue['pending'] }}</strong>{{ $queue['oldest_minutes'] !== null ? ' (oldest '.$queue['oldest_minutes'].' min)' : '' }} · Failed: <strong class="{{ $queue['failed'] ? 'text-rose-700' : '' }}">{{ $queue['failed'] }}</strong></p>
            @if ($queue['stalled'])<p class="mt-1 text-amber-700">⚠ Tasks are waiting but nothing is processing them. Start a worker or the cron scheduler.</p>@endif
            <p class="mt-2">Scheduler: <strong class="{{ $ok($scheduler['running']) }}">{{ $scheduler['running'] ? 'running' : 'no heartbeat in 3 min' }}</strong>
                @if ($scheduler['last_beat'])<span class="text-xs text-slate-400">(last {{ jp_ago($scheduler['last_beat']) }})</span>@endif</p>
            @unless ($scheduler['running'])<p class="text-xs text-slate-500">Add the cron entry from the README, or use the manual buttons — everything still works by hand.</p>@endunless
        </div>
        <div class="card card-pad text-sm">
            <h2 class="h-section">Gemini</h2>
            <p class="mt-2">Model <code>{{ $ai['model'] }}</code> · key {{ $ai['key_present'] ? 'present' : 'missing' }}</p>
            <p>Status: <strong class="{{ $ok($ai['available']) }}">{{ $ai['available'] ? 'ready' : \Illuminate\Support\Str::headline($ai['state']) }}</strong></p>
            <p>Local budget today: {{ $ai['calls_today'] }} / {{ $ai['limit'] }} · resets {{ jp_dt($ai['resets_at'], 'M j, g:i A') }}</p>
            @if ($ai['paused_until'])<p class="text-amber-700">Paused until {{ jp_dt($ai['paused_until']) }} ({{ $ai['pause_reason'] }})</p>@endif
            @unless ($ai['available'])<p class="mt-1 text-xs text-slate-500">{{ $ai['message'] }}</p>@endunless
        </div>
        <div class="card card-pad text-sm">
            <h2 class="h-section">System</h2>
            <ul class="mt-2 space-y-0.5">
                <li>PHP {{ $system['php'] }} · Laravel {{ $system['laravel'] }}</li>
                <li>Environment: {{ $system['env'] }} · DB: {{ $system['database'] }}</li>
                <li class="{{ $ok(! $system['debug'] || $system['env'] !== 'production') }}">Debug mode: {{ $system['debug'] ? 'ON' : 'off' }}{{ $system['debug'] && $system['env'] === 'production' ? ' — turn off in production!' : '' }}</li>
                <li class="{{ $ok($system['https'] || $system['env'] !== 'production') }}">HTTPS URL: {{ $system['https'] ? 'yes' : 'no' }}</li>
                <li class="{{ $ok($system['storage_writable'] && $system['cache_writable']) }}">storage/ &amp; bootstrap/cache writable: {{ $system['storage_writable'] && $system['cache_writable'] ? 'yes' : 'NO' }}</li>
                <li class="{{ $ok($system['mail']['ok']) }}">Mail: {{ $system['mail']['label'] }}</li>
                <li>Google OAuth: {{ $system['google_configured'] ? 'configured' : 'not configured (optional)' }}</li>
                <li>Free disk: {{ $system['free_disk_gb'] }} GB</li>
            </ul>
        </div>
    </section>

    <section aria-labelledby="runs-h">
        <h2 id="runs-h" class="h-section mb-3">Source syncs</h2>
        @if ($runs->isEmpty())<p class="text-sm text-slate-500">No syncs yet.</p>@else
        <div class="table-wrap"><table class="table-base">
            <thead><tr><th scope="col">When</th><th scope="col">Source</th><th scope="col">Result</th><th scope="col">Fetched</th><th scope="col">New</th><th scope="col">Updated</th><th scope="col">Error</th></tr></thead>
            <tbody>@foreach ($runs as $r)<tr>
                <td class="whitespace-nowrap text-slate-500">{{ jp_dt($r->started_at, 'M j, g:i A') }} <span class="text-xs">({{ $r->trigger }})</span></td>
                <td>{{ $r->source?->name }}</td>
                <td><x-badge :tone="$tone[$r->status] ?? 'slate'" dot>{{ $r->status }}</x-badge></td>
                <td class="tabular-nums">{{ $r->fetched }}</td><td class="tabular-nums">{{ $r->created }}</td><td class="tabular-nums">{{ $r->updated }}</td>
                <td class="max-w-sm text-xs text-rose-700">{{ $r->error }}</td></tr>@endforeach</tbody>
        </table></div>@endif
    </section>

    <section aria-labelledby="ai-h">
        <h2 id="ai-h" class="h-section mb-3">Recent AI calls <span class="text-xs font-normal text-slate-400">(no prompts are stored)</span></h2>
        @if ($aiLogs->isEmpty())<p class="text-sm text-slate-500">No AI calls yet.</p>@else
        <div class="table-wrap"><table class="table-base">
            <thead><tr><th scope="col">When</th><th scope="col">Task</th><th scope="col">Model</th><th scope="col">Status</th><th scope="col">Tokens in/out</th><th scope="col">Latency</th><th scope="col">Detail</th></tr></thead>
            <tbody>@foreach ($aiLogs as $l)<tr>
                <td class="whitespace-nowrap text-slate-500">{{ jp_dt($l->created_at, 'M j, g:i:s A') }}</td>
                <td>{{ $l->task }}</td><td class="font-mono text-xs">{{ $l->model }}</td>
                <td><x-badge :tone="$tone[$l->status] ?? 'slate'" dot>{{ $l->status }}</x-badge></td>
                <td class="tabular-nums text-slate-600">{{ $l->input_tokens ?? '–' }} / {{ $l->output_tokens ?? '–' }}</td>
                <td class="tabular-nums text-slate-600">{{ $l->latency_ms }} ms</td>
                <td class="max-w-xs text-xs text-slate-500">{{ $l->error_code }} {{ $l->error_message }}</td></tr>@endforeach</tbody>
        </table></div>@endif
    </section>

    <section aria-labelledby="n-h">
        <h2 id="n-h" class="h-section mb-3">E-mail notifications</h2>
        @if ($notifications->isEmpty())<p class="text-sm text-slate-500">No notifications yet. Use “Send test e-mail” in Settings.</p>@else
        <div class="table-wrap"><table class="table-base">
            <thead><tr><th scope="col">Created</th><th scope="col">Type</th><th scope="col">To</th><th scope="col">Status</th><th scope="col">Attempts</th><th scope="col">Error</th></tr></thead>
            <tbody>@foreach ($notifications as $n)<tr>
                <td class="whitespace-nowrap text-slate-500">{{ jp_dt($n->created_at, 'M j, g:i A') }}</td>
                <td>{{ \Illuminate\Support\Str::headline($n->type) }}</td><td class="text-slate-600">{{ $n->recipient }}</td>
                <td><x-badge :tone="$tone[$n->status] ?? 'slate'" dot>{{ $n->status }}</x-badge></td>
                <td class="tabular-nums">{{ $n->attempts }}</td><td class="max-w-sm text-xs text-rose-700">{{ \Illuminate\Support\Str::limit($n->last_error, 160) }}</td></tr>@endforeach</tbody>
        </table></div>@endif
    </section>

    <section aria-labelledby="a-h">
        <h2 id="a-h" class="h-section mb-3">Audit trail</h2>
        @if ($audit->isEmpty())<p class="text-sm text-slate-500">Nothing recorded yet.</p>@else
        <ul class="card divide-y divide-slate-100 text-sm">@foreach ($audit as $a)
            <li class="flex justify-between gap-3 p-3"><span class="font-mono text-xs text-slate-700">{{ $a->action }}{{ $a->subject_type ? ' · '.$a->subject_type.' #'.$a->subject_id : '' }}</span><span class="shrink-0 text-xs text-slate-400">{{ jp_dt($a->created_at, 'M j, g:i A') }}</span></li>@endforeach</ul>@endif
    </section>
</x-layouts.app>
