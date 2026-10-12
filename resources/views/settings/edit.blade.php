@php
    $labels = ['skills' => 'Relevant technical skills', 'duties' => 'Duties & responsibilities', 'seniority' => 'Experience & seniority', 'projects' => 'Project evidence', 'salary' => 'Salary preference', 'freshness' => 'Freshness'];
    $toggle = fn ($k) => old($k, $s[$k] ?? false);
@endphp
<x-layouts.app title="Settings" max="max-w-4xl">
    <x-page-header title="Settings" subtitle="Everything here is editable. Changes to scoring re-score your jobs automatically." />

    <form method="POST" action="{{ route('settings.update') }}" class="space-y-6" novalidate>
        @csrf @method('PUT')

        <section class="card card-pad space-y-4" aria-labelledby="s1">
            <h2 id="s1" class="h-section">What you're looking for</h2>
            <x-field label="Target roles (comma or line separated)" name="target_roles"><textarea id="f-target_roles" name="target_roles" rows="2" class="input">{{ old('target_roles', implode(', ', $s['target_roles'])) }}</textarea></x-field>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field label="Location preference" name="location_preference"><input id="f-location_preference" name="location_preference" class="input" value="{{ old('location_preference', $s['location_preference']) }}"></x-field>
                <x-field label="Your country" name="home_country" hint="Lets remote jobs be marked open / restricted / unclear. Not inferred from your time zone.">
                    <input id="f-home_country" name="home_country" list="countries" class="input" value="{{ old('home_country', $s['home_country']) }}">
                    <datalist id="countries">@foreach ($countries as $c)<option value="{{ $c }}">@endforeach</datalist>
                </x-field>
            </div>
            <fieldset><legend class="label">Experience levels to consider</legend>
                <div class="flex flex-wrap gap-4 text-sm">@foreach (['junior' => 'Junior / entry', 'mid' => 'Mid-level', 'senior' => 'Senior'] as $v => $l)
                    <label class="flex items-center gap-2"><input type="checkbox" class="check" name="experience_levels[]" value="{{ $v }}" @checked(in_array($v, old('experience_levels', $s['experience_levels'])))> {{ $l }}</label>@endforeach</div>
            </fieldset>
        </section>

        <section class="card card-pad space-y-4" aria-labelledby="s2">
            <h2 id="s2" class="h-section">Scoring</h2>
            <p class="text-sm text-slate-600">Weights are relative (they're normalised to 100). A factor that can't be assessed for a job — for example salary when you set no preference — is dropped and its weight shared among the others.</p>
            <div class="grid gap-4 sm:grid-cols-3">
                @foreach ($labels as $k => $l)
                    <x-field :label="$l" :name="'weights.'.$k"><input id="f-weights-{{ $k }}" type="number" min="0" max="100" step="1" name="weights[{{ $k }}]" class="input" value="{{ old('weights.'.$k, $weights[$k]) }}"></x-field>
                @endforeach
            </div>
            @error('weights')<p class="error">{{ $message }}</p>@enderror
            <div class="grid gap-4 sm:grid-cols-3">
                <x-field label="“Strong match” from" name="threshold_strong"><input id="f-threshold_strong" type="number" min="2" max="100" name="threshold_strong" class="input" value="{{ old('threshold_strong', $s['threshold_strong']) }}"></x-field>
                <x-field label="“Potential match” from" name="threshold_potential" hint="Below this = low match, hidden from the main feed."><input id="f-threshold_potential" type="number" min="1" max="99" name="threshold_potential" class="input" value="{{ old('threshold_potential', $s['threshold_potential']) }}"></x-field>
                <x-field label="AI pre-filter score" name="threshold_ai_prefilter" hint="Only jobs scoring at least this by the free rules are sent to Gemini."><input id="f-threshold_ai_prefilter" type="number" min="0" max="100" name="threshold_ai_prefilter" class="input" value="{{ old('threshold_ai_prefilter', $s['threshold_ai_prefilter']) }}"></x-field>
            </div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" class="check" name="show_low_matches" value="1" @checked($toggle('show_low_matches'))> Always show low matches in the feed</label>
        </section>

        <section class="card card-pad space-y-4" aria-labelledby="s2b">
            <h2 id="s2b" class="h-section">Exclusion rules</h2>
            <p class="text-sm text-slate-600">Jobs that trip a rule are still scored (so you can see what they are) but are hidden from your feed, dashboard and alerts, and never use an AI call. Use “Include jobs my rules exclude” in the feed filters to review them.</p>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field label="Blocked companies (one per line)" name="blocked_companies" hint="Whole-name match, e.g. “Acme” blocks “Acme Corp” but not “Acmeology”.">
                    <textarea id="f-blocked_companies" name="blocked_companies" rows="4" class="input">{{ old('blocked_companies', implode("\n", $s['blocked_companies'] ?? [])) }}</textarea>
                </x-field>
                <x-field label="Excluded keywords (one per line)" name="exclude_keywords" hint="Whole words, matched in the title or description (unpaid, commission only). Prefix with title: to match the job title only (title:manager, title:intern).">
                    <textarea id="f-exclude_keywords" name="exclude_keywords" rows="4" class="input">{{ old('exclude_keywords', implode("\n", $s['exclude_keywords'] ?? [])) }}</textarea>
                </x-field>
            </div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" class="check" name="exclude_below_min_salary" value="1" @checked($toggle('exclude_below_min_salary'))> Exclude jobs whose stated salary is below my minimum (same currency only — never converted)</label>
        </section>

        <section class="card card-pad space-y-4" aria-labelledby="s3">
            <h2 id="s3" class="h-section">Discovery &amp; notifications</h2>
            <x-field label="Check each source at most every (minutes)" name="scan_frequency_minutes" hint="Each source also has its own minimum (Remote OK 120 min, Greenhouse/Lever 30 min)."><input id="f-scan_frequency_minutes" type="number" min="15" max="1440" name="scan_frequency_minutes" class="input sm:!w-40" value="{{ old('scan_frequency_minutes', $s['scan_frequency_minutes']) }}"></x-field>
            <fieldset class="grid gap-2 text-sm sm:grid-cols-2">
                <legend class="label">E-mail me about</legend>
                @foreach (['notify_strong_matches' => 'Strong matches', 'notify_drafts_awaiting' => 'Drafts awaiting my approval', 'notify_responses' => 'Recruiter responses', 'notify_interviews' => 'Interviews & assessments', 'notify_deadlines' => 'Approaching deadlines', 'notify_source_failures' => 'Failing job sources'] as $k => $l)
                    <label class="flex items-center gap-2"><input type="checkbox" class="check" name="{{ $k }}" value="1" @checked($toggle($k))> {{ $l }}</label>
                @endforeach
            </fieldset>
            <div class="grid gap-4 rounded-lg border border-slate-200 p-4 sm:grid-cols-3">
                <x-field label="E-mail digest of new matches" name="digest_frequency" hint="One e-mail with your best new jobs (and saved searches).">
                    <select id="f-digest_frequency" name="digest_frequency" class="input">
                        @foreach (['daily' => 'Every day', 'weekly' => 'Every Monday', 'off' => 'Off'] as $v => $l)<option value="{{ $v }}" @selected(old('digest_frequency', $s['digest_frequency']) === $v)>{{ $l }}</option>@endforeach
                    </select>
                </x-field>
                <x-field label="Send at (your time zone)" name="digest_hour"><select id="f-digest_hour" name="digest_hour" class="input">@foreach (range(0, 23) as $h)<option value="{{ $h }}" @selected((int) old('digest_hour', $s['digest_hour']) === $h)>{{ sprintf('%02d:00', $h) }}</option>@endforeach</select></x-field>
                <x-field label="Max jobs per digest" name="digest_max_items"><input id="f-digest_max_items" type="number" min="1" max="30" name="digest_max_items" class="input" value="{{ old('digest_max_items', $s['digest_max_items']) }}"></x-field>
                <x-field label="Nudge me to follow up after (days)" name="followup_days" hint="Submitted applications with no reply. 0 = off. Nothing is ever sent for you." class="sm:col-span-2">
                    <input id="f-followup_days" type="number" min="0" max="60" name="followup_days" class="input sm:!w-32" value="{{ old('followup_days', $s['followup_days']) }}">
                </x-field>
                <label class="flex items-end gap-2 pb-2 text-sm"><input type="checkbox" class="check" name="notify_followups" value="1" @checked($toggle('notify_followups'))> E-mail me follow-up nudges</label>
            </div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" class="check" name="store_message_bodies" value="1" @checked($toggle('store_message_bodies'))> Store the text of job-related e-mails (off = only a short snippet is kept)</label>
        </section>

        <section class="card card-pad space-y-4" aria-labelledby="s4">
            <h2 id="s4" class="h-section">AI budget guardrails</h2>
            <x-field label="Daily Gemini call limit (local safety guard)" name="gemini_daily_limit" hint="Counted per Google quota day. Default {{ config('jobpilot.gemini.daily_call_limit') }} from GEMINI_DAILY_CALL_LIMIT. This is NOT Google's real quota — see AI Studio for that.">
                <input id="f-gemini_daily_limit" type="number" min="0" max="2000" name="gemini_daily_limit" class="input sm:!w-40" value="{{ old('gemini_daily_limit', $s['gemini_daily_limit'] ?? '') }}" placeholder="{{ config('jobpilot.gemini.daily_call_limit') }}">
            </x-field>
        </section>

        <div><button class="btn btn-primary">Save settings</button></div>
    </form>

    {{-- Privacy & AI --}}
    <section class="card card-pad space-y-4" aria-labelledby="s5">
        <h2 id="s5" class="h-section">Privacy &amp; AI (Gemini free tier)</h2>
        <dl class="grid gap-3 text-sm sm:grid-cols-2">
            <div><dt class="text-slate-500">Model</dt><dd class="font-mono text-slate-800">{{ $ai['model'] }}</dd></div>
            <div><dt class="text-slate-500">API key</dt><dd class="text-slate-800">{{ $ai['key_present'] ? 'Present on the server' : 'Not configured (GEMINI_API_KEY)' }}</dd></div>
            <div><dt class="text-slate-500">Status</dt><dd><x-badge :tone="$ai['available'] ? 'emerald' : 'amber'" dot>{{ $ai['available'] ? 'Ready' : \Illuminate\Support\Str::headline($ai['state']) }}</x-badge></dd></div>
            <div><dt class="text-slate-500">Your consent</dt><dd class="text-slate-800">{{ $profile->ai_consent === true ? ($profile->aiConsentGiven() ? 'Allowed' : 'Allowed under an older policy — please re-confirm') : 'Declined' }}@if ($profile->privacy_consent_at) <span class="text-xs text-slate-400">({{ jp_date($profile->privacy_consent_at) }}, policy {{ $profile->privacy_consent_version }})</span>@endif</dd></div>
        </dl>
        @if ($ai['model_warning'])<p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-inset ring-amber-200">{{ $ai['model_warning'] }}</p>@endif
        @unless ($ai['available'])<p class="text-sm text-slate-600">{{ $ai['message'] }}</p>@endunless
        <p class="text-sm text-slate-600">Free-tier prompts may be used by Google to improve its products and may be human-reviewed, so only <a class="underline" href="{{ route('privacy.show') }}">redacted data</a> is ever sent.</p>
        <div class="flex flex-wrap gap-2">
            <form method="POST" action="{{ route('settings.consent') }}">@csrf
                @if ($profile->aiConsentGiven())<button name="decision" value="decline" class="btn btn-secondary">Turn AI off</button>
                @else<button name="decision" value="accept" class="btn btn-accent">Allow redacted AI analysis</button>@endif
            </form>
            <form method="POST" action="{{ route('settings.test-ai') }}">@csrf<button class="btn btn-secondary">Test Gemini connection (1 call)</button></form>
        </div>
    </section>

    {{-- Account --}}
    <section class="card card-pad" aria-labelledby="s6">
        <h2 id="s6" class="h-section">Account &amp; e-mail</h2>
        <form method="POST" action="{{ route('settings.account') }}" class="mt-4 space-y-4" novalidate>
            @csrf @method('PUT')
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field label="Name" name="name"><input id="f-name" name="name" class="input" value="{{ old('name', $user->name) }}" required></x-field>
                <x-field label="Time zone" name="timezone" hint="Times are stored in UTC and displayed in this zone."><select id="f-timezone" name="timezone" class="input">@foreach ($timezones as $tz)<option value="{{ $tz }}" @selected(old('timezone', $user->timezone) === $tz)>{{ $tz }}</option>@endforeach</select></x-field>
                <x-field label="Send alerts to" name="notification_email" hint="Leave empty to use {{ $user->email }}."><input id="f-notification_email" type="email" name="notification_email" class="input" value="{{ old('notification_email', $user->notification_email) }}"></x-field>
                <div class="flex items-end"><p class="text-sm text-slate-500">Mail transport: <strong class="{{ $mail['ok'] ? 'text-emerald-700' : 'text-amber-700' }}">{{ $mail['label'] }}</strong><br><span class="text-xs">{{ $mail['detail'] }}</span></p></div>
            </div>
            <details class="rounded-lg border border-slate-200 p-3">
                <summary class="cursor-pointer text-sm font-medium text-slate-700">Change password</summary>
                <div class="mt-3 grid gap-3 sm:grid-cols-3">
                    <x-field label="Current password" name="current_password"><input id="f-current_password" type="password" name="current_password" class="input" autocomplete="current-password"></x-field>
                    <x-field label="New password (12+)" name="password"><input id="f-password" type="password" name="password" class="input" autocomplete="new-password"></x-field>
                    <x-field label="Repeat" name="password_confirmation"><input id="f-password_confirmation" type="password" name="password_confirmation" class="input" autocomplete="new-password"></x-field>
                </div>
            </details>
            <button class="btn btn-primary">Save account</button>
        </form>
        <form method="POST" action="{{ route('settings.test-email') }}" class="mt-4 border-t border-slate-100 pt-4">@csrf
            <button class="btn btn-secondary"><x-icon name="mail" class="h-4 w-4" /> Send test e-mail</button>
            <span class="ml-2 text-xs text-slate-500">Delivery results appear under Activity &amp; Diagnostics.</span>
        </form>
        <form method="POST" action="{{ route('settings.send-digest') }}" class="mt-3">@csrf
            <button class="btn btn-secondary"><x-icon name="sparkles" class="h-4 w-4" /> Send a digest of the last 7 days now</button>
            <span class="ml-2 text-xs text-slate-500">Preview of what the scheduled digest looks like.</span>
        </form>
    </section>

    {{-- Export --}}
    <section class="card card-pad" aria-labelledby="s6b">
        <h2 id="s6b" class="h-section">Export my data</h2>
        <p class="mt-1 text-sm text-slate-600">A portable JSON copy of your profile, CV text, applications (with letters, approvals and history), interviews, saved searches and settings — handy as a backup or to move elsewhere. Secrets (API keys, OAuth tokens, source credentials, password hash) and CV files are <strong>not</strong> included.</p>
        <a href="{{ route('settings.export') }}" class="btn btn-secondary mt-3" data-no-lock><x-icon name="download" class="h-4 w-4" /> Download JSON export</a>
    </section>

    {{-- Danger --}}
    <section class="card card-pad border-rose-200" aria-labelledby="s7">
        <h2 id="s7" class="h-section text-rose-800">Delete my data</h2>
        <p class="mt-1 text-sm text-slate-600">Removes your profile and versions, CV files, matches, applications and history, interviews, stored e-mail data, notification and AI logs, and disconnects Gmail. <strong>It cannot delete anything held by Google or any employer</strong> — only this installation's data. Your login stays.</p>
        <form method="POST" action="{{ route('settings.delete-data') }}" class="mt-4 grid gap-3 sm:grid-cols-3" x-data="confirmSubmit('This permanently deletes your local data. Continue?')" @submit="check($event)">
            @csrf @method('DELETE')
            <x-field label="Type DELETE to confirm" name="confirm_text"><input id="f-confirm_text" name="confirm_text" class="input" autocomplete="off"></x-field>
            <x-field label="Your password" name="password"><input id="f-del-password" type="password" name="password" class="input" autocomplete="current-password"></x-field>
            <div class="flex flex-col justify-end gap-2 text-sm">
                <label class="flex items-center gap-2"><input type="checkbox" class="check" name="include_jobs" value="1"> Also delete fetched jobs</label>
                <button class="btn btn-danger">Delete my data</button>
            </div>
        </form>
    </section>
</x-layouts.app>
