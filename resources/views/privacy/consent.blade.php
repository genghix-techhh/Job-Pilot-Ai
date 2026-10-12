<x-layouts.guest title="Privacy choice">
    <div class="-mx-2 sm:-mx-4">
        <div class="flex items-center gap-3">
            <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-amber-50 text-amber-600 ring-1 ring-amber-200"><x-icon name="shield" class="h-6 w-6" /></span>
            <h1 class="text-xl font-bold text-slate-900">Before you start: AI &amp; your data</h1>
        </div>

        @if (session('status'))
            <p class="mt-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-900 ring-1 ring-emerald-200">{{ session('status') }}</p>
        @endif

        <div class="mt-4 space-y-3 text-sm leading-relaxed text-slate-700">
            <p>
                JobPilot can use Google's <strong>Gemini API free tier</strong> (model <code class="rounded bg-slate-100 px-1 text-xs">{{ $model }}</code>) to structure your CV, read job requirements, explain matches and polish drafts.
            </p>
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-amber-950">
                <p class="font-semibold">Important about the free tier</p>
                <p class="mt-1">Google's terms for unpaid Gemini usage say that prompts and responses may be used to improve Google products and may be reviewed by humans. That is why JobPilot removes personal details <em>before</em> anything leaves this server, and why this choice is yours.</p>
            </div>
            @include('privacy._data-table')
            <ul class="list-disc space-y-1 pl-5">
                <li>Free-tier limits apply. If they are reached, AI work simply waits; nothing is billed and no other model is ever used.</li>
                <li>If you decline, <strong>everything except Gemini keeps working</strong>: discovery, rule-based scoring, drafts from your own words, tracking, reminders.</li>
                <li>You can change this at any time under Settings → Privacy &amp; AI. Details are on the <a class="font-medium underline" href="{{ route('privacy.show') }}" target="_blank">privacy page</a>.</li>
            </ul>
            @unless ($keyPresent)
                <p class="rounded-lg bg-slate-50 p-3 text-slate-600 ring-1 ring-inset ring-slate-200">
                    Note: no <code>GEMINI_API_KEY</code> is configured on the server yet, so AI stays inactive until you add one — your choice is still saved.
                </p>
            @endunless
        </div>

        <form method="POST" action="{{ route('privacy.consent.store') }}" class="mt-6 grid gap-3 sm:grid-cols-2">
            @csrf
            <button name="decision" value="accept" class="btn btn-accent">Allow redacted AI analysis</button>
            <button name="decision" value="decline" class="btn btn-secondary">No thanks — keep AI off</button>
        </form>
        <p class="mt-3 text-center text-xs text-slate-400">Policy version {{ config('jobpilot.privacy_version') }}</p>
    </div>
</x-layouts.guest>
