@php
    $lines = fn ($v) => is_array($v) ? implode("\n", $v) : (string) $v;
    $work = collect(old('structured.work_history', $structured['work_history'] ?? []))->map(fn ($w) => [
        'title' => $w['title'] ?? '', 'company' => $w['company'] ?? '', 'start' => $w['start'] ?? '', 'end' => $w['end'] ?? '',
        'current' => (bool) ($w['current'] ?? false), 'bullets' => $lines($w['bullets'] ?? []),
    ])->values()->all();
    $projects = collect(old('structured.projects', $structured['projects'] ?? []))->map(fn ($p) => [
        'name' => $p['name'] ?? '', 'description' => $p['description'] ?? '',
        'technologies' => is_array($p['technologies'] ?? null) ? implode(', ', $p['technologies']) : ($p['technologies'] ?? ''), 'url' => $p['url'] ?? '',
    ])->values()->all();
    $education = collect(old('structured.education', $structured['education'] ?? []))->map(fn ($e) => ['degree' => $e['degree'] ?? '', 'school' => $e['school'] ?? '', 'year' => $e['year'] ?? ''])->values()->all();
    $certs = collect(old('structured.certifications', $structured['certifications'] ?? []))->map(fn ($c) => ['name' => $c['name'] ?? '', 'issuer' => $c['issuer'] ?? '', 'year' => $c['year'] ?? ''])->values()->all();
    $links = collect(old('structured.links', $structured['links'] ?? []))->map(fn ($l) => ['label' => $l['label'] ?? '', 'url' => $l['url'] ?? ''])->values()->all();
    $remote = $profile->remote_preferences ?? [];
    $salary = $profile->salary_preferences ?? [];
    $levels = old('experience_levels', $profile->experience_levels ?: ['junior', 'mid', 'senior']);
    $types = old('employment_types', $profile->employment_types ?: ['full_time', 'contract', 'freelance']);
@endphp
<x-layouts.app title="CV & Profile" max="max-w-5xl">
    <x-page-header title="CV &amp; Profile" subtitle="Upload your CV, check every parsed field, and save. Scoring and drafts only ever use the profile you reviewed here." />

    {{-- CV upload --}}
    <section class="card card-pad" aria-labelledby="cv-h">
        <h2 id="cv-h" class="h-section">Your CV</h2>
        <form method="POST" action="{{ route('resume.store') }}" enctype="multipart/form-data" class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-end">
            @csrf
            <x-field label="Upload or replace (PDF, DOCX or TXT, max {{ round(config('jobpilot.uploads.max_kb') / 1024, 1) }} MB)" name="cv" class="flex-1">
                <input id="f-cv" type="file" name="cv" accept=".pdf,.docx,.txt" class="input file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-medium" required>
            </x-field>
            <button class="btn btn-accent"><x-icon name="download" class="h-4 w-4 rotate-180" /> Upload &amp; parse</button>
        </form>
        <p class="mt-2 text-xs text-slate-500"><x-icon name="lock" class="inline h-3.5 w-3.5" /> Stored in a private folder, parsed on this server. Scanned/image-only PDFs can't be read (no OCR yet) — use a text PDF or DOCX.</p>

        @if ($resumes->isNotEmpty())
            <ul class="mt-4 divide-y divide-slate-100 rounded-lg border border-slate-200">
                @foreach ($resumes as $r)
                    <li class="flex flex-wrap items-center gap-3 p-3 text-sm">
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium text-slate-800">v{{ $r->version }} · {{ $r->original_filename }}</p>
                            <p class="text-xs text-slate-500">{{ $r->humanSize() }} · uploaded {{ jp_ago($r->created_at) }} · sha256 {{ substr($r->checksum, 0, 10) }}…</p>
                            @if ($r->parse_status !== 'parsed')<p class="text-xs text-rose-600">{{ $r->parse_error }}</p>@endif
                        </div>
                        @if ($r->is_active)<x-badge tone="emerald" dot>Master CV</x-badge>@endif
                        <div class="flex gap-1">
                            <a href="{{ route('resume.download', $r) }}" class="btn btn-ghost btn-sm">Download</a>
                            @if ($r->parse_status === 'parsed')
                                @unless ($r->is_active)
                                    <form method="POST" action="{{ route('resume.activate', $r) }}">@csrf<button class="btn btn-ghost btn-sm">Make master</button></form>
                                @endunless
                                <form method="POST" action="{{ route('resume.reparse', $r) }}">@csrf<button class="btn btn-ghost btn-sm">Re-parse</button></form>
                            @endif
                            @unless ($r->is_active)
                                <form method="POST" action="{{ route('resume.destroy', $r) }}" x-data="confirmSubmit('Delete this CV file permanently?')" @submit="check($event)">@csrf @method('DELETE')
                                    <button class="btn btn-ghost btn-sm text-rose-600" aria-label="Delete CV v{{ $r->version }}"><x-icon name="trash" class="h-4 w-4" /></button>
                                </form>
                            @endunless
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @if ($pending)
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">
            <p class="font-semibold">Review needed: these fields were just parsed from your CV.</p>
            <p class="mt-1">{{ $pending['note'] }} Nothing is used for matching until you press <strong>Save reviewed profile</strong>.</p>
            <form method="POST" action="{{ route('profile.discard') }}" class="mt-2">@csrf<button class="btn btn-secondary btn-sm">Discard parsed data</button></form>
        </div>
    @elseif (! $profile->profile_version)
        <x-empty icon="user" title="No profile yet" text="Upload your CV above, or fill in the form below by hand and save." />
    @endif

    <form method="POST" action="{{ route('profile.update') }}" class="space-y-6" novalidate>
        @csrf @method('PUT')

        <section class="card card-pad space-y-4" aria-labelledby="contact-h">
            <h2 id="contact-h" class="h-section">Contact &amp; summary <span class="ml-1 text-xs font-normal text-slate-400">(kept local — never sent to Gemini)</span></h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field label="Full name" name="structured.name"><input id="f-structured-name" name="structured[name]" value="{{ old('structured.name', $structured['name']) }}" class="input"></x-field>
                <x-field label="Headline" name="structured.headline"><input id="f-structured-headline" name="structured[headline]" value="{{ old('structured.headline', $structured['headline']) }}" class="input" placeholder="e.g. Full-stack developer"></x-field>
                <x-field label="E-mail" name="structured.email"><input id="f-structured-email" type="email" name="structured[email]" value="{{ old('structured.email', $structured['email']) }}" class="input"></x-field>
                <x-field label="Phone" name="structured.phone"><input id="f-structured-phone" name="structured[phone]" value="{{ old('structured.phone', $structured['phone']) }}" class="input"></x-field>
                <x-field label="Location" name="structured.location" class="sm:col-span-2"><input id="f-structured-location" name="structured[location]" value="{{ old('structured.location', $structured['location']) }}" class="input"></x-field>
            </div>
            <x-field label="Professional summary" name="structured.summary" hint="Write only what is true — drafts reuse your own words.">
                <textarea id="f-structured-summary" name="structured[summary]" rows="3" class="input">{{ old('structured.summary', $structured['summary']) }}</textarea>
            </x-field>
            <div x-data="repeater(@js($links), { label: '', url: '' })">
                <p class="label">Links (LinkedIn, GitHub, portfolio …)</p>
                <template x-for="(row, i) in rows" :key="row._k ?? i">
                    <div class="mb-2 flex gap-2">
                        <input class="input !w-36" :name="`structured[links][${i}][label]`" x-model="row.label" placeholder="Label" aria-label="Link label">
                        <input class="input" :name="`structured[links][${i}][url]`" x-model="row.url" placeholder="https://…" aria-label="Link URL">
                        <button type="button" class="btn btn-ghost btn-sm" @click="remove(i)" aria-label="Remove link"><x-icon name="x" class="h-4 w-4" /></button>
                    </div>
                </template>
                <button type="button" class="btn btn-secondary btn-sm" @click="add()"><x-icon name="plus" class="h-4 w-4" /> Add link</button>
            </div>
            <x-field label="Portfolio URLs (one per line)" name="structured.portfolio_urls">
                <textarea id="f-structured-portfolio_urls" name="structured[portfolio_urls]" rows="2" class="input">{{ old('structured.portfolio_urls', implode("\n", $structured['portfolio_urls'] ?? [])) }}</textarea>
            </x-field>
        </section>

        <section class="card card-pad space-y-4" aria-labelledby="skills-h">
            <h2 id="skills-h" class="h-section">Skills &amp; targets</h2>
            <x-field label="Skills (comma or line separated)" name="skills" hint="Only list skills you can honestly claim. These are matched against job requirements.">
                <textarea id="f-skills" name="skills" rows="3" class="input">{{ old('skills', implode(', ', $skills)) }}</textarea>
            </x-field>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field label="Target roles (comma separated)" name="roles" hint="Suggested: {{ implode(', ', $roleOptions) }}">
                    <textarea id="f-roles" name="roles" rows="2" class="input">{{ old('roles', implode(', ', $roles)) }}</textarea>
                </x-field>
                <x-field label="Total years of professional experience" name="years_experience" hint="Used to compare against what a job asks for.">
                    <input id="f-years_experience" type="number" step="0.5" min="0" max="60" name="years_experience" value="{{ old('years_experience', $years) }}" class="input">
                </x-field>
            </div>
            <fieldset>
                <legend class="label">Experience levels you'll consider</legend>
                <div class="flex flex-wrap gap-4 text-sm">
                    @foreach (['junior' => 'Junior / entry', 'mid' => 'Mid-level', 'senior' => 'Senior'] as $v => $l)
                        <label class="flex items-center gap-2"><input type="checkbox" class="check" name="experience_levels[]" value="{{ $v }}" @checked(in_array($v, $levels))> {{ $l }}</label>
                    @endforeach
                </div>
                <p class="hint">Levels you leave out are down-weighted, never excluded — scoring still follows your real CV evidence.</p>
            </fieldset>
            <fieldset>
                <legend class="label">Employment types</legend>
                <div class="flex flex-wrap gap-4 text-sm">
                    @foreach (['full_time' => 'Full-time', 'part_time' => 'Part-time', 'contract' => 'Contract', 'freelance' => 'Freelance', 'internship' => 'Internship'] as $v => $l)
                        <label class="flex items-center gap-2"><input type="checkbox" class="check" name="employment_types[]" value="{{ $v }}" @checked(in_array($v, $types))> {{ $l }}</label>
                    @endforeach
                </div>
            </fieldset>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field label="Work location preference" name="remote_location"><input id="f-remote_location" name="remote_location" value="{{ old('remote_location', $remote['location'] ?? 'Remote worldwide') }}" class="input"></x-field>
                <x-field label="Your country (for remote eligibility)" name="home_country" hint="Needed to say whether a remote job is open to you. Never assumed from your time zone.">
                    <input id="f-home_country" name="home_country" list="countries" value="{{ old('home_country', $homeCountry) }}" class="input" placeholder="e.g. Pakistan">
                    <datalist id="countries">@foreach ($countries as $c)<option value="{{ $c }}">@endforeach</datalist>
                </x-field>
                <x-field label="Preferred countries / regions" name="preferred_countries"><input id="f-preferred_countries" name="preferred_countries" value="{{ old('preferred_countries', implode(', ', $remote['countries'] ?? [])) }}" class="input"></x-field>
                <x-field label="Preferred time zones" name="preferred_timezones"><input id="f-preferred_timezones" name="preferred_timezones" value="{{ old('preferred_timezones', implode(', ', $remote['timezones'] ?? [])) }}" class="input" placeholder="e.g. UTC+1 to UTC+5"></x-field>
            </div>
            <div class="grid gap-4 sm:grid-cols-3">
                <x-field label="Minimum salary (optional)" name="salary_min" hint="Leave empty if you have no preference — the salary factor is then ignored, not scored as zero.">
                    <input id="f-salary_min" type="number" min="0" name="salary_min" value="{{ old('salary_min', $salary['min'] ?? '') }}" class="input">
                </x-field>
                <x-field label="Currency" name="salary_currency"><input id="f-salary_currency" name="salary_currency" maxlength="3" value="{{ old('salary_currency', $salary['currency'] ?? 'USD') }}" class="input uppercase"></x-field>
                <x-field label="Per" name="salary_period">
                    <select id="f-salary_period" name="salary_period" class="input">
                        @foreach (['year' => 'Year', 'month' => 'Month', 'hour' => 'Hour'] as $v => $l)<option value="{{ $v }}" @selected(old('salary_period', $salary['period'] ?? 'year') === $v)>{{ $l }}</option>@endforeach
                    </select>
                </x-field>
            </div>
        </section>

        <section class="card card-pad" aria-labelledby="work-h" x-data="repeater(@js($work), { title: '', company: '', start: '', end: '', current: false, bullets: '' })">
            <h2 id="work-h" class="h-section">Work history</h2>
            <p class="mt-1 text-sm text-slate-500">Most recent first. One achievement per line — drafts quote these lines, so keep them accurate.</p>
            <template x-for="(row, i) in rows" :key="row._k ?? i">
                <div class="mt-4 rounded-lg border border-slate-200 p-4">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div><label class="label">Job title</label><input class="input" :name="`structured[work_history][${i}][title]`" x-model="row.title"></div>
                        <div><label class="label">Employer <span class="font-normal text-slate-400">(local only)</span></label><input class="input" :name="`structured[work_history][${i}][company]`" x-model="row.company"></div>
                        <div><label class="label">Start</label><input class="input" :name="`structured[work_history][${i}][start]`" x-model="row.start" placeholder="Jan 2021"></div>
                        <div><label class="label">End</label><input class="input" :name="`structured[work_history][${i}][end]`" x-model="row.end" placeholder="Mar 2023" :disabled="row.current"></div>
                    </div>
                    <label class="mt-2 flex items-center gap-2 text-sm"><input type="checkbox" class="check" value="1" :name="`structured[work_history][${i}][current]`" x-model="row.current"> I currently work here</label>
                    <div class="mt-2"><label class="label">Responsibilities &amp; achievements (one per line)</label><textarea rows="4" class="input" :name="`structured[work_history][${i}][bullets]`" x-model="row.bullets"></textarea></div>
                    <button type="button" class="btn btn-danger-outline btn-sm mt-3" @click="remove(i)"><x-icon name="trash" class="h-4 w-4" /> Remove</button>
                </div>
            </template>
            <button type="button" class="btn btn-secondary btn-sm mt-4" @click="add()"><x-icon name="plus" class="h-4 w-4" /> Add position</button>
        </section>

        <section class="card card-pad" aria-labelledby="proj-h" x-data="repeater(@js($projects), { name: '', description: '', technologies: '', url: '' })">
            <h2 id="proj-h" class="h-section">Projects &amp; portfolio</h2>
            <p class="mt-1 text-sm text-slate-500">Project evidence is 15% of the score and powers freelance proposals. Remove client names you shouldn't disclose.</p>
            <template x-for="(row, i) in rows" :key="row._k ?? i">
                <div class="mt-4 rounded-lg border border-slate-200 p-4">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div><label class="label">Name</label><input class="input" :name="`structured[projects][${i}][name]`" x-model="row.name"></div>
                        <div><label class="label">Link</label><input class="input" :name="`structured[projects][${i}][url]`" x-model="row.url" placeholder="https://…"></div>
                    </div>
                    <div class="mt-2"><label class="label">Description</label><textarea rows="3" class="input" :name="`structured[projects][${i}][description]`" x-model="row.description"></textarea></div>
                    <div class="mt-2"><label class="label">Technologies (comma separated)</label><input class="input" :name="`structured[projects][${i}][technologies]`" x-model="row.technologies"></div>
                    <button type="button" class="btn btn-danger-outline btn-sm mt-3" @click="remove(i)"><x-icon name="trash" class="h-4 w-4" /> Remove</button>
                </div>
            </template>
            <button type="button" class="btn btn-secondary btn-sm mt-4" @click="add()"><x-icon name="plus" class="h-4 w-4" /> Add project</button>
        </section>

        <div class="grid gap-6 md:grid-cols-2">
            <section class="card card-pad" aria-labelledby="edu-h" x-data="repeater(@js($education), { degree: '', school: '', year: '' })">
                <h2 id="edu-h" class="h-section">Education</h2>
                <template x-for="(row, i) in rows" :key="row._k ?? i">
                    <div class="mt-3 space-y-2 rounded-lg border border-slate-200 p-3">
                        <input class="input" :name="`structured[education][${i}][degree]`" x-model="row.degree" placeholder="Degree" aria-label="Degree">
                        <div class="flex gap-2">
                            <input class="input" :name="`structured[education][${i}][school]`" x-model="row.school" placeholder="School" aria-label="School">
                            <input class="input !w-24" :name="`structured[education][${i}][year]`" x-model="row.year" placeholder="Year" aria-label="Year">
                        </div>
                        <button type="button" class="btn btn-ghost btn-sm text-rose-600" @click="remove(i)">Remove</button>
                    </div>
                </template>
                <button type="button" class="btn btn-secondary btn-sm mt-3" @click="add()"><x-icon name="plus" class="h-4 w-4" /> Add</button>
            </section>
            <section class="card card-pad" aria-labelledby="cert-h" x-data="repeater(@js($certs), { name: '', issuer: '', year: '' })">
                <h2 id="cert-h" class="h-section">Certifications</h2>
                <template x-for="(row, i) in rows" :key="row._k ?? i">
                    <div class="mt-3 space-y-2 rounded-lg border border-slate-200 p-3">
                        <input class="input" :name="`structured[certifications][${i}][name]`" x-model="row.name" placeholder="Certification" aria-label="Certification">
                        <div class="flex gap-2">
                            <input class="input" :name="`structured[certifications][${i}][issuer]`" x-model="row.issuer" placeholder="Issuer" aria-label="Issuer">
                            <input class="input !w-24" :name="`structured[certifications][${i}][year]`" x-model="row.year" placeholder="Year" aria-label="Year">
                        </div>
                        <button type="button" class="btn btn-ghost btn-sm text-rose-600" @click="remove(i)">Remove</button>
                    </div>
                </template>
                <button type="button" class="btn btn-secondary btn-sm mt-3" @click="add()"><x-icon name="plus" class="h-4 w-4" /> Add</button>
            </section>
        </div>

        <div class="sticky bottom-0 -mx-4 flex items-center justify-between gap-3 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-xl sm:border">
            <p class="text-xs text-slate-500">{{ $profile->profile_version ? 'Current version: v'.$profile->profile_version : 'Not saved yet' }}@if ($profile->reviewed_at) · last reviewed {{ jp_ago($profile->reviewed_at) }}@endif</p>
            <button class="btn btn-accent">Save reviewed profile</button>
        </div>
    </form>

    @if ($versions->isNotEmpty())
        <section class="card card-pad">
            <h2 class="h-section">Saved versions</h2>
            <p class="mt-1 text-xs text-slate-500">Each save that changes something creates an immutable version. Match scores and drafts record which version they used.</p>
            <ul class="mt-3 divide-y divide-slate-100 text-sm">
                @foreach ($versions as $v)<li class="flex justify-between py-2"><span>v{{ $v->version }} · {{ $v->reason }}</span><span class="text-slate-400">{{ jp_dt($v->created_at) }}</span></li>@endforeach
            </ul>
        </section>
    @endif
</x-layouts.app>
