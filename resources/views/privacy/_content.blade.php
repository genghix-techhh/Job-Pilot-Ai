<div class="space-y-6">
    <x-page-header title="Privacy &amp; data" subtitle="What this installation stores, what it can send to Google's Gemini free tier, and how to remove it all." />

    <section class="card card-pad space-y-3">
        <h2 class="h-section">What is stored locally</h2>
        <p class="text-sm text-slate-600">Everything lives in your own database and private storage folder on your server. There are no analytics, trackers, or third-party scripts in this app.</p>
        <ul class="list-disc space-y-1 pl-5 text-sm text-slate-700">
            <li><strong>CV files</strong> in a private folder that is never served over HTTP (downloaded only through an authenticated page), plus the text extracted from them and your reviewed profile (with immutable versions).</li>
            <li><strong>Jobs</strong> fetched from the sources you enabled, your scores and match explanations.</li>
            <li><strong>Applications</strong>: drafts, approvals (who, when, document hashes, job URL), status history.</li>
            <li><strong>Gmail (optional)</strong>: encrypted OAuth tokens, and for job-related mail only the sender, subject, a 300-character snippet and extracted details. Full bodies are <em>not</em> stored unless you switch that on.</li>
            <li><strong>Operational logs</strong>: AI call counts/latency/status (never prompts), notification delivery status, audit records for approvals and security events.</li>
        </ul>
    </section>

    <section class="card card-pad space-y-3">
        <h2 class="h-section">What Gemini (free tier) can receive — and what it cannot</h2>
        <p class="text-sm text-slate-600">Model: <code class="rounded bg-slate-100 px-1 text-xs">{{ $model }}</code>. Calls are made server-side only; the API key never reaches your browser. Policy version {{ $version }}.</p>
        @include('privacy._data-table')
        <p class="text-sm text-slate-600">
            Job descriptions and e-mails are treated as <strong>untrusted data</strong>: they are wrapped in delimiters, the model is told never to follow instructions inside them, its answers must match a strict JSON schema, and any evidence it cites must exist in what was actually sent.
            Before every call a final scan refuses to send a prompt that still contains an e-mail address, phone number, ID number or your name.
        </p>
    </section>

    <section class="card card-pad space-y-3">
        <h2 class="h-section">Free-tier honesty</h2>
        <ul class="list-disc space-y-1 pl-5 text-sm text-slate-700">
            <li>Google may use unpaid-tier prompts and responses to improve its products and they may be human-reviewed. Paid tiers are treated differently by Google; this app does not use them.</li>
            <li>No paid fallback model, no billed tools (such as search grounding) and no extra keys are ever used. When the quota is reached, AI work waits.</li>
            <li>You can turn AI off at any time. The rest of the app does not depend on it.</li>
        </ul>
    </section>

    <section class="card card-pad space-y-3">
        <h2 class="h-section">Deleting your data</h2>
        <p class="text-sm text-slate-700">Settings → <em>Delete my data</em> removes your profile, CV files, matches, applications, interviews, stored messages, notification and AI logs, and disconnects Gmail. <strong>It cannot delete anything held by Google</strong> (your Gmail messages, your AI Studio project, or anything Google already received) or by any employer or job board — you would have to ask them directly.</p>
    </section>

    <section class="card card-pad space-y-2">
        <h2 class="h-section">Applications</h2>
        <p class="text-sm text-slate-700">The app never submits an application on its own. Every submission needs your explicit approval and then either an authorised integration (none ships by default) or you applying on the employer's site and confirming it here.</p>
    </section>

    @auth
        <p><a href="{{ route('settings.edit') }}" class="btn btn-secondary">Open Privacy &amp; AI settings <x-icon name="arrow-right" class="h-4 w-4" /></a></p>
    @endauth
</div>
