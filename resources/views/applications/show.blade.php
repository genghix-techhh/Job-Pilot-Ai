@php
    use App\Enums\ApplicationStatus as S;
    $st = $app->status;
    $editable = $st->isEditable();
    $answers = old('screening_answers', $app->screening_answers ?? []);
    $restricted = $match?->eligibility_status === \App\Enums\EligibilityStatus::Restricted;
    $submitted = $app->submitted_at !== null;
    $steps = [
        ['Draft created', true],
        ['You review & edit', in_array($st, [S::AwaitingApproval, S::Approved, S::SubmissionInProgress, S::Submitted]) || $submitted],
        ['You approve', $app->approved_at !== null || $submitted],
        ['Apply on the official page', $submitted],
        ['You confirm it was submitted', $submitted],
    ];
@endphp
<x-layouts.app :title="'Application · '.$job->title">
    <nav aria-label="Breadcrumb" class="text-sm text-slate-500">
        <a href="{{ route('applications.index') }}" class="hover:text-amber-700 hover:underline">Applications</a> <span aria-hidden="true">/</span>
        <a href="{{ route('jobs.show', $job) }}" class="hover:text-amber-700 hover:underline">{{ \Illuminate\Support\Str::limit($job->title, 50) }}</a>
    </nav>

    <header class="card card-pad">
        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
            <div class="min-w-0">
                <h1 class="text-2xl font-bold tracking-tight text-slate-900">{{ $job->title }}</h1>
                <p class="mt-1 text-slate-600">{{ $job->company ?: 'Company not stated' }} · {{ $job->source->name }} · {{ $app->type === 'freelance' ? 'Freelance proposal' : 'Job application' }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('applications.prep', $app) }}" class="btn btn-secondary btn-sm"><x-icon name="sparkles" class="h-4 w-4" /> Interview prep</a>
                <x-badge :tone="$st->tone()" dot>{{ $st->label() }}</x-badge>
                @if ($submitted)
                    <x-badge tone="emerald"><x-icon name="check" class="h-3.5 w-3.5" /> Submitted {{ jp_date($app->submitted_at) }} ({{ $app->submission_method === 'api' ? 'confirmed by integration' : 'confirmed by you' }})</x-badge>
                @else
                    <x-badge tone="slate"><x-icon name="lock" class="h-3.5 w-3.5" /> NOT SUBMITTED</x-badge>
                @endif
            </div>
        </div>
        {{-- progress --}}
        <ol class="mt-4 grid gap-2 text-xs sm:grid-cols-5" aria-label="Progress">
            @foreach ($steps as $i => [$label, $done])
                <li class="flex items-center gap-2 rounded-lg px-2.5 py-2 ring-1 ring-inset {{ $done ? 'bg-emerald-50 text-emerald-900 ring-emerald-200' : 'bg-slate-50 text-slate-500 ring-slate-200' }}">
                    <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[11px] font-bold {{ $done ? 'bg-emerald-500 text-white' : 'bg-slate-200 text-slate-600' }}">@if ($done)<x-icon name="check" class="h-3 w-3" />@else{{ $i + 1 }}@endif</span>
                    {{ $label }}
                </li>
            @endforeach
        </ol>
    </header>

    @if (($artifacts['ai_status'] ?? 'none') === 'pending')
        <div class="flex items-center gap-3 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900"><x-icon name="sparkles" class="h-5 w-5" /> {{ $artifacts['ai_note'] ?? 'Gemini is polishing this draft…' }} Reload in a moment — the template draft below is already usable.</div>
    @elseif (! empty($artifacts['ai_note']))
        <p class="text-xs text-slate-500"><x-icon name="info" class="inline h-4 w-4" /> {{ $artifacts['ai_note'] }}</p>
    @endif
    @if (! empty($artifacts['ai_suggestion']))
        <div class="flex flex-wrap items-center gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">
            <x-icon name="sparkles" class="h-5 w-5 text-amber-600" />
            <p class="flex-1">Gemini prepared an alternative draft after you had started editing, so your text was left untouched.</p>
            <form method="POST" action="{{ route('applications.adopt', $app) }}" x-data="confirmSubmit('Replace your cover letter and CV text with the AI suggestion?')" @submit="check($event)">@csrf<button class="btn btn-accent btn-sm">Replace my text with it</button></form>
        </div>
    @endif

    @if ($restricted)
        <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900"><strong>Location restricted:</strong> {{ $match->eligibility_note }}</div>
    @elseif ($match?->eligibility_status === \App\Enums\EligibilityStatus::Unclear)
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><strong>Eligibility unclear:</strong> {{ $match->eligibility_note }} Check the posting before you apply.</div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- ============ LEFT: documents ============ --}}
        <div class="space-y-6 lg:col-span-2">
            <form method="POST" action="{{ route('applications.update', $app) }}" class="space-y-6">
                @csrf @method('PUT')

                <section class="card card-pad" aria-labelledby="cl-h">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 id="cl-h" class="h-section">{{ $app->type === 'freelance' ? 'Proposal' : 'Cover letter' }}</h2>
                        <div class="flex gap-1 text-xs">
                            <a class="btn btn-secondary btn-sm" href="{{ route('applications.export', [$app, 'cover-letter', 'format' => 'pdf']) }}"><x-icon name="download" class="h-3.5 w-3.5" /> PDF</a>
                            <a class="btn btn-ghost btn-sm" href="{{ route('applications.export', [$app, 'cover-letter', 'format' => 'txt']) }}">TXT</a>
                            <a class="btn btn-ghost btn-sm" href="{{ route('applications.export', [$app, 'cover-letter', 'format' => 'html']) }}">HTML</a>
                            <a class="btn btn-ghost btn-sm" target="_blank" href="{{ route('applications.export', [$app, 'cover-letter', 'format' => 'print']) }}">Print / PDF</a>
                        </div>
                    </div>
                    <label for="cover_letter" class="sr-only">Cover letter</label>
                    <textarea id="cover_letter" name="cover_letter" rows="14" class="input mt-3 font-[inherit]" @disabled(! $editable)>{{ old('cover_letter', $app->cover_letter) }}</textarea>
                    @error('cover_letter')<p class="error">{{ $message }}</p>@enderror
                </section>

                <section class="card card-pad" aria-labelledby="cv-h">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h2 id="cv-h" class="h-section">Tailored CV</h2>
                            <p class="text-xs text-slate-500">Built from your reviewed profile (v{{ $app->profile_version }}){{ $app->resume ? ' · master CV v'.$app->resume->version : '' }}. Skills and achievements are selected and re-ordered — never invented.</p>
                        </div>
                        <div class="flex gap-1 text-xs">
                            <a class="btn btn-secondary btn-sm" href="{{ route('applications.export', [$app, 'resume', 'format' => 'pdf']) }}"><x-icon name="download" class="h-3.5 w-3.5" /> PDF</a>
                            <a class="btn btn-ghost btn-sm" href="{{ route('applications.export', [$app, 'resume', 'format' => 'txt']) }}">TXT</a>
                            <a class="btn btn-ghost btn-sm" href="{{ route('applications.export', [$app, 'resume', 'format' => 'html']) }}">HTML</a>
                            <a class="btn btn-ghost btn-sm" target="_blank" href="{{ route('applications.export', [$app, 'resume', 'format' => 'print']) }}">Print / PDF</a>
                        </div>
                    </div>
                    <label for="tailored_resume" class="sr-only">Tailored CV</label>
                    <textarea id="tailored_resume" name="tailored_resume" rows="16" class="input mt-3 font-mono text-[13px]" @disabled(! $editable)>{{ old('tailored_resume', $app->tailored_resume) }}</textarea>
                    @error('tailored_resume')<p class="error">{{ $message }}</p>@enderror
                </section>

                <section class="card card-pad" aria-labelledby="qa-h">
                    <h2 id="qa-h" class="h-section">Screening questions</h2>
                    <p class="mt-1 text-xs text-slate-500">Questions about work authorisation, visas, salary, relocation, notice period or personal facts are always left for you — the app never guesses them.</p>
                    @forelse ($answers as $i => $row)
                        <div class="mt-4 rounded-lg p-3 ring-1 ring-inset {{ blank($row['answer'] ?? null) ? 'bg-amber-50 ring-amber-300' : (! empty($row['needs_confirmation']) ? 'bg-amber-50/50 ring-amber-200' : 'bg-slate-50 ring-slate-200') }}">
                            <input type="hidden" name="screening_answers[{{ $i }}][question]" value="{{ $row['question'] ?? '' }}">
                            <label for="qa-{{ $i }}" class="text-sm font-medium text-slate-800">{{ $row['question'] ?? '' }}</label>
                            @if (blank($row['answer'] ?? null))<p class="text-xs font-semibold text-amber-800">⚠ Needs your answer before you can approve.</p>
                            @elseif (! empty($row['needs_confirmation']))<p class="text-xs font-semibold text-amber-800">Needs your confirmation — check this is true.</p>@endif
                            <textarea id="qa-{{ $i }}" name="screening_answers[{{ $i }}][answer]" rows="3" class="input mt-2" @disabled(! $editable)>{{ $row['answer'] ?? '' }}</textarea>
                            <label class="mt-2 flex items-center gap-2 text-xs text-slate-600"><input type="checkbox" class="check" name="screening_answers[{{ $i }}][needs_confirmation]" value="1" @checked(! empty($row['needs_confirmation'])) @disabled(! $editable)> Flag for double-check</label>
                            @foreach ($row['evidence'] ?? [] as $ev)<p class="mt-1 text-xs text-slate-500">↳ <span class="font-mono">{{ $ev['ref'] }}</span> {{ $ev['text'] }}</p>@endforeach
                        </div>
                    @empty
                        <p class="mt-3 text-sm text-slate-500">No questions yet. Add the employer's questions below.</p>
                    @endforelse
                </section>

                <section class="card card-pad">
                    <label for="notes" class="label">Private notes</label>
                    <textarea id="notes" name="notes" rows="3" class="input" @disabled(! $editable)>{{ old('notes', $app->notes) }}</textarea>
                </section>

                @if ($editable)
                    <div class="flex flex-wrap items-center gap-3">
                        <button class="btn btn-primary">Save draft</button>
                        <span class="text-xs text-slate-500">Editing an approved draft revokes the approval.</span>
                    </div>
                @endif
            </form>

            @if ($editable)
                <div class="grid gap-4 sm:grid-cols-2">
                    <form method="POST" action="{{ route('applications.question', $app) }}" class="card card-pad space-y-2">@csrf
                        <label for="new-q" class="label">Add a screening question</label>
                        <input id="new-q" name="question" class="input" maxlength="500" placeholder="Paste a question from the application form" required>
                        <button class="btn btn-secondary btn-sm">Add question</button>
                    </form>
                    <form method="POST" action="{{ route('applications.regenerate', $app) }}" class="card card-pad space-y-2" x-data="confirmSubmit('Rebuild the draft from your profile? Your edits to the letter and CV text will be replaced.')" @submit="check($event)">@csrf
                        <p class="label">Start over</p>
                        <p class="text-xs text-slate-500">Rebuild the letter and CV from your latest profile (uses Gemini only if enabled).</p>
                        <button class="btn btn-secondary btn-sm"><x-icon name="refresh" class="h-4 w-4" /> Regenerate draft</button>
                    </form>
                </div>
            @endif

            {{-- Evidence --}}
            @if (! empty($artifacts['paragraphs']) || ! empty($artifacts['warnings']) || ! empty($artifacts['open_questions']))
                <section class="card card-pad" aria-labelledby="ev-h">
                    <h2 id="ev-h" class="h-section">Evidence &amp; warnings</h2>
                    <p class="mt-1 text-xs text-slate-500">Every statement in the generated letter should trace to your profile. Edits you make afterwards are not tracked here — re-check anything you add.</p>
                    @foreach ($artifacts['warnings'] ?? [] as $w)<p class="mt-3 flex gap-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-inset ring-amber-200"><x-icon name="warning" class="mt-0.5 h-4 w-4 shrink-0 text-amber-600" /> {{ $w }}</p>@endforeach
                    @foreach ($artifacts['open_questions'] ?? [] as $q)<p class="mt-2 text-sm text-slate-600">❓ {{ $q }}</p>@endforeach
                    <details class="mt-3">
                        <summary class="cursor-pointer text-sm font-medium text-slate-700">Show what each paragraph is based on</summary>
                        <ul class="mt-2 space-y-3">
                            @foreach ($artifacts['paragraphs'] ?? [] as $p)
                                <li class="rounded-lg bg-slate-50 p-3 text-sm ring-1 ring-inset {{ ($p['verified'] ?? true) ? 'ring-slate-200' : 'ring-amber-300' }}">
                                    <p class="text-slate-800">{{ $p['text'] }}</p>
                                    @forelse ($p['evidence'] ?? [] as $ev)<p class="mt-1 text-xs text-slate-500">↳ <span class="font-mono">{{ $ev['ref'] }}</span> {{ \Illuminate\Support\Str::limit($ev['text'], 160) }}</p>
                                    @empty<p class="mt-1 text-xs {{ ($p['verified'] ?? true) ? 'text-slate-400' : 'font-semibold text-amber-700' }}">{{ ($p['verified'] ?? true) ? 'Greeting/closing — no factual claim.' : 'Needs your confirmation: no supporting evidence.' }}</p>@endforelse
                                </li>
                            @endforeach
                        </ul>
                    </details>
                </section>
            @endif
        </div>

        {{-- ============ RIGHT: workflow ============ --}}
        <aside class="space-y-6">
            <section class="card card-pad" aria-labelledby="flow-h">
                <h2 id="flow-h" class="h-section">Approval &amp; submission</h2>

                @if ($st === S::DraftReady || $st === S::AwaitingApproval)
                    <p class="mt-2 text-sm text-slate-600">Nothing leaves this app until you approve <em>and</em> apply on the employer's page yourself.</p>
                    @if ($st === S::DraftReady)
                        <form method="POST" action="{{ route('applications.request', $app) }}" class="mt-3">@csrf<button class="btn btn-secondary btn-sm">Mark as ready for approval</button></form>
                    @endif
                    <form method="POST" action="{{ route('applications.approve', $app) }}" class="mt-4 space-y-3 border-t border-slate-100 pt-4">@csrf
                        <p class="text-sm font-semibold text-slate-900">Approve this draft</p>
                        @if ($unanswered)<p class="rounded-lg bg-amber-50 p-2 text-xs text-amber-900 ring-1 ring-inset ring-amber-200">{{ $unanswered }} question(s) still need your answer.</p>@endif
                        <label class="flex gap-2 text-sm text-slate-700"><input type="checkbox" class="check mt-0.5" name="reviewed" value="1"> I read the cover letter, CV and answers.</label>
                        <label class="flex gap-2 text-sm text-slate-700"><input type="checkbox" class="check mt-0.5" name="truthful" value="1"> Everything in them is accurate and mine to claim.</label>
                        @if ($restricted)<label class="flex gap-2 text-sm text-rose-800"><input type="checkbox" class="check mt-0.5" name="eligibility_ack" value="1"> I know this job looks restricted for my location and want to continue.</label>@endif
                        <button class="btn btn-accent w-full"><x-icon name="check" class="h-4 w-4" /> Approve (does not submit)</button>
                    </form>
                    <form method="POST" action="{{ route('applications.reject', $app) }}" class="mt-3" x-data="confirmSubmit('Reject and close this draft? Nothing has been sent.')" @submit="check($event)">@csrf
                        <button class="btn btn-danger-outline btn-sm w-full">Reject draft</button>
                    </form>

                @elseif ($st === S::Approved || $st === S::SubmissionInProgress || $st === S::SubmissionFailed)
                    @if ($app->approved_at)
                        <dl class="mt-3 space-y-1 text-xs text-slate-600">
                            <div><dt class="inline font-medium">Approved:</dt> <dd class="inline">{{ jp_dt($app->approved_at) }}</dd></div>
                            <div class="break-all"><dt class="inline font-medium">Job link:</dt> <dd class="inline">{{ $app->approved_url }}</dd></div>
                            <div><dt class="inline font-medium">Document hashes:</dt> <dd class="inline font-mono">{{ substr($app->approved_hashes['cover_letter'] ?? '', 0, 8) }} · {{ substr($app->approved_hashes['tailored_resume'] ?? '', 0, 8) }}</dd></div>
                        </dl>
                    @endif
                    @if ($st === S::SubmissionFailed)<p class="mt-3 rounded-lg bg-rose-50 p-2 text-sm text-rose-900 ring-1 ring-inset ring-rose-200">The last automatic submission failed. Nothing is recorded as submitted.</p>@endif

                    @if ($st === S::Approved && $approvalCurrent)
                        <p class="mt-3 text-sm font-semibold text-slate-900">1. Apply on the official page</p>
                        <form method="POST" action="{{ route('applications.open', $app) }}" target="_blank" class="mt-2">@csrf
                            <button class="btn btn-primary w-full" data-no-lock><x-icon name="external" class="h-4 w-4" /> Open official application page</button>
                        </form>
                        <p class="mt-1 text-xs text-slate-500">Opening the page does <strong>not</strong> mark anything as submitted. Copy your documents from the tabs on the left.</p>

                        <form method="POST" action="{{ route('applications.submitted', $app) }}" class="mt-5 space-y-3 border-t border-slate-100 pt-4">@csrf
                            <p class="text-sm font-semibold text-slate-900">2. Confirm after you applied</p>
                            <label class="flex gap-2 text-sm text-slate-700"><input type="checkbox" class="check mt-0.5" name="confirm" value="1"> I submitted this application on the employer's site.</label>
                            @error('confirm')<p class="error">{{ $message }}</p>@enderror
                            <div><label class="label" for="ref">Confirmation reference (optional)</label><input id="ref" name="reference" class="input" maxlength="190"></div>
                            <div><label class="label" for="on">Date submitted (optional)</label><input id="on" type="date" name="submitted_on" max="{{ now(user_tz())->toDateString() }}" class="input"></div>
                            <button class="btn btn-accent w-full">I submitted this</button>
                        </form>

                        @if ($gateway)
                            <form method="POST" action="{{ route('applications.submit', $app) }}" class="mt-4" x-data="confirmSubmit('Send this application now through {{ $gateway->name() }}?')" @submit="check($event)">@csrf
                                <button class="btn btn-secondary w-full">Submit via {{ $gateway->name() }}</button>
                            </form>
                        @else
                            <p class="mt-4 text-xs text-slate-500">No authorised submission integration exists for this source, so applying is manual by design.</p>
                        @endif
                    @elseif ($st === S::SubmissionInProgress)
                        <p class="mt-3 text-sm text-slate-600">A submission is in progress. If it appears stuck:</p>
                        <form method="POST" action="{{ route('applications.status', $app) }}" class="mt-2">@csrf<input type="hidden" name="status" value="submission_failed"><button class="btn btn-secondary btn-sm">Mark as failed (nothing was submitted)</button></form>
                    @endif

                    @if ($st !== S::SubmissionInProgress)
                        <form method="POST" action="{{ route('applications.revoke', $app) }}" class="mt-4">@csrf<button class="btn btn-ghost btn-sm w-full">Revoke approval &amp; keep editing</button></form>
                    @endif

                @elseif ($submitted)
                    <p class="mt-2 text-sm text-slate-600">Submitted {{ jp_dt($app->submitted_at) }}{{ $app->external_reference ? ' · ref '.$app->external_reference : '' }}.</p>
                    <form method="POST" action="{{ route('applications.status', $app) }}" class="mt-3 space-y-2">@csrf
                        <label class="label" for="new-status">Update status</label>
                        <select id="new-status" name="status" class="input">
                            @foreach ($ownerStatuses as $o)@if ($st->canTransitionTo($o) && $o !== S::DraftReady)<option value="{{ $o->value }}">{{ $o->label() }}</option>@endif @endforeach
                        </select>
                        <input name="note" class="input" maxlength="200" placeholder="Note (optional)">
                        <button class="btn btn-secondary btn-sm">Update</button>
                    </form>
                @elseif ($st === S::Closed)
                    <p class="mt-2 text-sm text-slate-600">This draft is closed. Nothing was sent.</p>
                    <form method="POST" action="{{ route('applications.status', $app) }}" class="mt-3">@csrf<input type="hidden" name="status" value="draft_ready"><button class="btn btn-secondary btn-sm">Reopen as draft</button></form>
                @endif
            </section>

            @if ($followUp)
                <section class="card card-pad" aria-labelledby="fu-h" x-data="{ copied: false }">
                    <h2 id="fu-h" class="h-section">Follow up</h2>
                    @if ($followUp['due'])
                        <p class="mt-1 rounded-lg bg-amber-50 p-2 text-sm text-amber-900 ring-1 ring-inset ring-amber-200">No reply for <strong>{{ $followUp['days'] }} days</strong> — a polite nudge is reasonable now.</p>
                    @else
                        <p class="mt-1 text-xs text-slate-500">Submitted {{ $followUp['days'] }} day(s) ago. A follow-up becomes worthwhile after {{ settings()->get('followup_days') ?: '—' }} days.</p>
                    @endif
                    <label for="fu-msg" class="label mt-3">Message to copy (JobPilot never sends it)</label>
                    <textarea id="fu-msg" rows="9" readonly class="input text-[13px]">{{ $followUp['message'] }}</textarea>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <button type="button" class="btn btn-secondary btn-sm" data-no-lock @click="navigator.clipboard.writeText(document.getElementById('fu-msg').value).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"><span x-show="!copied">Copy message</span><span x-show="copied" x-cloak>Copied ✓</span></button>
                        @if (! $app->next_task)
                            <form method="POST" action="{{ route('applications.followup.plan', $app) }}">@csrf<button class="btn btn-secondary btn-sm">Plan this as my next task</button></form>
                        @endif
                        @if ($followUp['due'])
                            <form method="POST" action="{{ route('applications.followup.dismiss', $app) }}">@csrf<button class="btn btn-ghost btn-sm">Not needed</button></form>
                        @endif
                    </div>
                </section>
            @endif

            @if ($submitted || $app->next_task)
                <form method="POST" action="{{ route('applications.tracking', $app) }}" class="card card-pad space-y-3">@csrf @method('PATCH')
                    <h2 class="h-section">Next step</h2>
                    <div><label class="label" for="nt">Next task</label><input id="nt" name="next_task" class="input" maxlength="190" value="{{ old('next_task', $app->next_task) }}" placeholder="e.g. Follow up by e-mail"></div>
                    <div><label class="label" for="nta">Due</label><input id="nta" type="datetime-local" name="next_task_at" class="input" value="{{ old('next_task_at', $app->next_task_at?->setTimezone(user_tz())->format('Y-m-d\TH:i')) }}"></div>
                    <button class="btn btn-secondary btn-sm">Save</button>
                </form>
            @endif

            <section class="card card-pad" aria-labelledby="tl-h">
                <h2 id="tl-h" class="h-section">History</h2>
                <ol class="mt-3 space-y-3 border-l border-slate-200 pl-4">
                    @foreach ($app->events as $e)
                        <li class="relative text-sm">
                            <span class="absolute -left-[21px] top-1.5 h-2.5 w-2.5 rounded-full bg-amber-400 ring-4 ring-white"></span>
                            <p class="font-medium text-slate-800">{{ \Illuminate\Support\Str::headline($e->type) }}@if ($e->to_status) → {{ \App\Enums\ApplicationStatus::tryFrom($e->to_status)?->label() }}@endif</p>
                            <p class="text-xs text-slate-400">{{ jp_dt($e->occurred_at) }} · {{ $e->actor }}</p>
                        </li>
                    @endforeach
                </ol>
            </section>

            @if ($app->interviews->isNotEmpty() || $app->messages->isNotEmpty())
                <section class="card card-pad text-sm">
                    <h2 class="h-section">Related</h2>
                    @foreach ($app->interviews as $i)<p class="mt-2"><a class="text-amber-700 hover:underline" href="{{ route('interviews.index') }}">{{ $i->title }}</a> · {{ ucfirst($i->state) }} · {{ jp_dt($i->when()) }}</p>@endforeach
                    @foreach ($app->messages as $m)<p class="mt-2 text-slate-600">✉ {{ $m->classification->label() }} — {{ \Illuminate\Support\Str::limit($m->subject, 50) }}</p>@endforeach
                </section>
            @endif
        </aside>
    </div>
</x-layouts.app>
