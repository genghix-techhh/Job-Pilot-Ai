<x-layouts.app title="Inbox Monitoring">
    <x-page-header title="Inbox Monitoring" subtitle="Optional. With read-only Gmail access JobPilot looks for recruiter replies, interview invitations and assessments. It never sends, replies, accepts invitations or deletes anything." />

    {{-- Connection --}}
    <section class="card card-pad" aria-labelledby="conn-h">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 id="conn-h" class="h-section">Gmail connection</h2>
                @if ($account && $account->status === 'connected')
                    <p class="mt-1 text-sm text-slate-600">Connected as <strong>{{ $account->email }}</strong> · last sync {{ $account->last_synced_at ? jp_ago($account->last_synced_at) : 'never' }} · scope: read-only</p>
                    @if ($account->last_error)<p class="mt-1 text-sm text-rose-600">{{ $account->last_error }}</p>@endif
                @elseif ($account && $account->status === 'revoked')
                    <p class="mt-1 text-sm text-amber-700">Disconnected / access revoked. {{ $account->last_error }}</p>
                @else
                    <p class="mt-1 text-sm text-slate-600">Not connected. The rest of the app works fine without it.</p>
                @endif
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <x-badge :tone="$account?->status === 'connected' ? 'emerald' : 'slate'" dot>{{ $account?->status === 'connected' ? 'Connected' : 'Not connected' }}</x-badge>
                @if ($account?->status === 'connected')
                    <form method="POST" action="{{ route('inbox.sync') }}">@csrf<button class="btn btn-accent btn-sm"><x-icon name="refresh" class="h-4 w-4" /> Sync now</button></form>
                    <form method="POST" action="{{ route('inbox.disconnect') }}" x-data="confirmSubmit('Disconnect Gmail and revoke access at Google?')" @submit="check($event)">@csrf<button class="btn btn-danger-outline btn-sm">Disconnect &amp; revoke</button></form>
                @elseif ($configured)
                    <a href="{{ route('inbox.connect') }}" class="btn btn-accent btn-sm">Connect Gmail (read-only)</a>
                @endif
            </div>
        </div>

        @unless ($configured)
            <div class="mt-4 rounded-lg bg-slate-50 p-4 text-sm text-slate-700 ring-1 ring-inset ring-slate-200">
                <p class="font-semibold text-slate-900">Setup (about 10 minutes, free)</p>
                <ol class="mt-2 list-decimal space-y-1 pl-5">
                    <li>In <strong>Google Cloud Console</strong> create a project, then enable the <strong>Gmail API</strong>.</li>
                    <li><em>OAuth consent screen</em>: User type External, app in “Testing”, add <strong>your own Gmail address as a test user</strong>, add scope <code>…/auth/gmail.readonly</code>.</li>
                    <li><em>Credentials → Create OAuth client ID → Web application</em>. Authorised redirect URI: <code class="break-all">{{ $redirectUri }}</code></li>
                    <li>Put the client id/secret in your server's <code>.env</code> as <code>GOOGLE_CLIENT_ID</code>, <code>GOOGLE_CLIENT_SECRET</code> (and <code>GOOGLE_REDIRECT_URI</code> if different), then run <code>php artisan config:clear</code> and reload this page.</li>
                </ol>
                <p class="mt-2 text-xs text-slate-500">While the app is in Testing mode Google expires the refresh token after 7 days; publish it as “In production” (single user, no verification needed for personal use with a warning screen) or reconnect weekly.</p>
            </div>
        @endunless
    </section>

    {{-- Needs review --}}
    <section aria-labelledby="rev-h">
        <h2 id="rev-h" class="h-section mb-3">Needs review <span class="text-slate-400">({{ $review->count() }})</span></h2>
        @forelse ($review as $m)
            <article class="card mb-3 p-4">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="font-medium text-slate-900">{{ $m->subject ?: '(no subject)' }}</p>
                        <p class="text-xs text-slate-500">{{ $m->from_name }} &lt;{{ $m->from_email }}&gt; · {{ jp_dt($m->received_at) }}</p>
                        <p class="mt-1 text-sm text-slate-600">{{ $m->snippet }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <x-badge tone="amber">Looks like: {{ $m->classification->label() }} ({{ round($m->confidence * 100) }}%)</x-badge>
                        @if ($m->web_link)<a href="{{ $m->web_link }}" target="_blank" rel="noopener noreferrer" class="btn btn-ghost btn-sm" title="Open in Gmail"><x-icon name="external" class="h-4 w-4" /></a>@endif
                    </div>
                </div>
                @if (! empty($m->extracted['proposed_times']))
                    <p class="mt-2 text-xs text-slate-600">Times mentioned (tentative):
                        @foreach ($m->extracted['proposed_times'] as $t)<span class="mr-2">{{ jp_dt(\Illuminate\Support\Carbon::parse($t['start_utc']), 'D M j g:i A') }}@unless ($t['tz_stated']) <em>(zone not stated)</em>@endunless</span>@endforeach
                    </p>
                @endif
                <form method="POST" action="{{ route('inbox.resolve', $m) }}" class="mt-3 flex flex-wrap items-end gap-2">@csrf
                    <div><label class="label" for="app-{{ $m->id }}">Belongs to</label>
                        <select id="app-{{ $m->id }}" name="application_id" class="input !w-64">
                            <option value="">— choose application —</option>
                            @foreach ($apps as $a)
                                <option value="{{ $a->id }}" @selected($m->application_id === $a->id || in_array($a->id, $m->candidate_application_ids ?? []))>{{ \Illuminate\Support\Str::limit($a->job->title.' · '.$a->job->company, 48) }}{{ in_array($a->id, $m->candidate_application_ids ?? []) ? ' (possible)' : '' }}</option>
                            @endforeach
                        </select></div>
                    <div><label class="label" for="cls-{{ $m->id }}">Type</label>
                        <select id="cls-{{ $m->id }}" name="classification" class="input !w-48">@foreach ($classes as $c)<option value="{{ $c->value }}" @selected($m->classification === $c)>{{ $c->label() }}</option>@endforeach</select></div>
                    <button class="btn btn-primary btn-sm">Confirm</button>
                </form>
                <form method="POST" action="{{ route('inbox.dismiss', $m) }}" class="mt-2">@csrf<button class="btn btn-ghost btn-sm">Dismiss — not job related</button></form>
            </article>
        @empty
            <x-empty icon="inbox" title="Nothing needs review" text="Messages that could belong to more than one application, or that the classifier is unsure about, wait here for you." />
        @endforelse
    </section>

    {{-- Matched --}}
    <section aria-labelledby="mat-h">
        <h2 id="mat-h" class="h-section mb-3">Matched messages</h2>
        @if ($matched->isEmpty())
            <p class="text-sm text-slate-500">No matched messages yet.</p>
        @else
            <div class="table-wrap">
                <table class="table-base">
                    <thead><tr><th scope="col">Received</th><th scope="col">From / subject</th><th scope="col">Type</th><th scope="col">Application</th><th scope="col"></th></tr></thead>
                    <tbody>
                    @foreach ($matched as $m)
                        <tr>
                            <td class="whitespace-nowrap text-slate-500">{{ jp_dt($m->received_at, 'M j, g:i A') }}</td>
                            <td class="min-w-56"><p class="font-medium text-slate-800">{{ \Illuminate\Support\Str::limit($m->subject, 70) }}</p><p class="text-xs text-slate-500">{{ $m->from_email }}</p><p class="text-xs text-slate-400">{{ \Illuminate\Support\Str::limit($m->snippet, 110) }}</p></td>
                            <td><x-badge tone="violet">{{ $m->classification->label() }}</x-badge></td>
                            <td>@if ($m->application)<a class="text-amber-700 hover:underline" href="{{ route('applications.show', $m->application) }}">{{ \Illuminate\Support\Str::limit($m->application->job->title, 40) }}</a>@endif</td>
                            <td>@if ($m->web_link)<a class="btn btn-ghost btn-sm" target="_blank" rel="noopener noreferrer" href="{{ $m->web_link }}">Open in Gmail</a>@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="card card-pad text-sm text-slate-600">
        <h2 class="h-section">Stored e-mail content</h2>
        <p class="mt-1">Only job-related messages are stored: sender, subject, a short snippet and extracted details. Full bodies are {{ $storeBodies ? 'currently stored (you enabled this in Settings)' : 'not stored' }}. {{ $bodiesStored }} message(s) currently hold a body.</p>
        @if ($bodiesStored)
            <form method="POST" action="{{ route('inbox.purge') }}" class="mt-2" x-data="confirmSubmit('Delete all stored message bodies?')" @submit="check($event)">@csrf<button class="btn btn-danger-outline btn-sm">Delete stored bodies</button></form>
        @endif
        <p class="mt-2 text-xs text-slate-500">Phone calls cannot be detected from e-mail. Add those by hand under Interviews.</p>
    </section>
</x-layouts.app>
