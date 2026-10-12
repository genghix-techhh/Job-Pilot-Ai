<x-layouts.app title="Interviews & Follow-ups">
    <x-page-header title="Interviews &amp; Follow-ups" subtitle="Times found in e-mails are always tentative until you confirm them. Every entry keeps its own time zone and can be downloaded as a calendar file." />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <section aria-labelledby="up-h">
                <h2 id="up-h" class="h-section mb-3">Upcoming</h2>
                @forelse ($upcoming->concat($undated) as $i)
                    <article class="card mb-3 p-4" x-data="{ edit: false }">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-badge :tone="$i->isConfirmed() ? 'emerald' : 'amber'" dot>{{ ucfirst($i->state) }}</x-badge>
                                    <x-badge tone="violet">{{ ucfirst(str_replace('_', ' ', $i->kind)) }}</x-badge>
                                </div>
                                <h3 class="mt-1 font-semibold text-slate-900">{{ $i->title }}</h3>
                                @if ($i->starts_at)
                                    <p class="text-sm text-slate-700">{{ jp_dt($i->starts_at, 'D, M j Y · g:i A') }} <span class="text-slate-400">({{ user_tz() }})</span></p>
                                    @if ($i->source_timezone !== user_tz())<p class="text-xs text-slate-500">{{ jp_dt($i->starts_at, 'g:i A', $i->source_timezone) }} in {{ $i->source_timezone }}</p>@endif
                                @endif
                                @if ($i->deadline_at)<p class="text-sm text-slate-700">Due {{ jp_dt($i->deadline_at, 'D, M j Y · g:i A') }}</p>@endif
                                @if (! $i->starts_at && ! $i->deadline_at)<p class="text-sm text-amber-700">No date yet — edit to add one.</p>@endif
                                @if ($i->meeting_url)<p class="text-sm"><a class="break-all text-amber-700 underline" target="_blank" rel="noopener noreferrer" href="{{ $i->meeting_url }}">{{ \Illuminate\Support\Str::limit($i->meeting_url, 70) }}</a></p>@endif
                                @if ($i->application)<p class="text-xs text-slate-500">For <a class="text-amber-700 hover:underline" href="{{ route('applications.show', $i->application) }}">{{ $i->application->job->title }}</a> · <a class="font-medium text-amber-700 hover:underline" href="{{ route('applications.prep', $i->application) }}">Interview prep →</a></p>@endif
                                @if ($i->notes)<p class="mt-1 whitespace-pre-line text-xs text-slate-500">{{ $i->notes }}</p>@endif
                            </div>
                            <div class="flex flex-wrap gap-1">
                                @unless ($i->isConfirmed())
                                    <form method="POST" action="{{ route('interviews.confirm', $i) }}">@csrf<button class="btn btn-accent btn-sm"><x-icon name="check" class="h-4 w-4" /> Confirm</button></form>
                                @endunless
                                @if ($i->starts_at || $i->deadline_at)<a href="{{ route('interviews.ics', $i) }}" class="btn btn-secondary btn-sm"><x-icon name="download" class="h-4 w-4" /> .ics</a>@endif
                                <button type="button" class="btn btn-ghost btn-sm" @click="edit = !edit" :aria-expanded="edit.toString()">Edit</button>
                                <form method="POST" action="{{ route('interviews.complete', $i) }}">@csrf<button class="btn btn-ghost btn-sm">Done</button></form>
                                <form method="POST" action="{{ route('interviews.cancel', $i) }}" x-data="confirmSubmit('Mark this as cancelled?')" @submit="check($event)">@csrf<button class="btn btn-ghost btn-sm text-rose-600">Cancel</button></form>
                            </div>
                        </div>
                        <form x-show="edit" x-cloak method="POST" action="{{ route('interviews.update', $i) }}" class="mt-4 grid gap-3 border-t border-slate-100 pt-4 sm:grid-cols-2">
                            @csrf @method('PUT')
                            <input type="hidden" name="kind" value="{{ $i->kind }}">
                            <div class="sm:col-span-2"><label class="label">Title</label><input name="title" class="input" value="{{ $i->title }}" required></div>
                            <div><label class="label">Time zone of this time</label>
                                <select name="source_timezone" class="input">@foreach ($timezones as $tz)<option value="{{ $tz }}" @selected($i->source_timezone === $tz)>{{ $tz }}</option>@endforeach</select></div>
                            <div><label class="label">Meeting link</label><input name="meeting_url" class="input" value="{{ $i->meeting_url }}"></div>
                            <div><label class="label">Starts</label><input type="datetime-local" name="starts_at_local" class="input" value="{{ $i->starts_at?->setTimezone($i->source_timezone)->format('Y-m-d\TH:i') }}"></div>
                            <div><label class="label">Ends</label><input type="datetime-local" name="ends_at_local" class="input" value="{{ $i->ends_at?->setTimezone($i->source_timezone)->format('Y-m-d\TH:i') }}"></div>
                            <div><label class="label">Deadline</label><input type="datetime-local" name="deadline_local" class="input" value="{{ $i->deadline_at?->setTimezone($i->source_timezone)->format('Y-m-d\TH:i') }}"></div>
                            <div class="sm:col-span-2"><label class="label">Notes</label><textarea name="notes" rows="2" class="input">{{ $i->notes }}</textarea></div>
                            <div class="sm:col-span-2"><button class="btn btn-primary btn-sm">Save changes</button></div>
                        </form>
                    </article>
                @empty
                    <x-empty icon="calendar" title="Nothing scheduled" text="Add an interview, assessment or follow-up reminder with the form, or connect Gmail so recruiter e-mails are detected for you." />
                @endforelse
            </section>

            @if ($past->isNotEmpty())
                <section aria-labelledby="past-h">
                    <h2 id="past-h" class="h-section mb-3">Earlier</h2>
                    <ul class="card divide-y divide-slate-100 text-sm">
                        @foreach ($past as $i)
                            <li class="flex items-center justify-between gap-3 p-3"><span class="truncate text-slate-700">{{ $i->title }}</span><span class="shrink-0 text-xs text-slate-400">{{ jp_dt($i->when(), 'M j, Y') }} · {{ ucfirst($i->state) }}</span></li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>

        <aside>
            <form method="POST" action="{{ route('interviews.store') }}" class="card card-pad space-y-3" novalidate>
                @csrf
                <h2 class="h-section">Add entry</h2>
                <x-field label="Type" name="kind"><select id="f-kind" name="kind" class="input">
                    <option value="interview" @selected(old('kind') === 'interview')>Interview</option>
                    <option value="assessment" @selected(old('kind') === 'assessment')>Assessment / test</option>
                    <option value="follow_up" @selected(old('kind') === 'follow_up')>Follow-up reminder</option></select></x-field>
                <x-field label="Title" name="title"><input id="f-title" name="title" class="input" value="{{ old('title') }}" required></x-field>
                <x-field label="Application (optional)" name="application_id"><select id="f-application_id" name="application_id" class="input"><option value="">—</option>
                    @foreach ($applications as $a)<option value="{{ $a->id }}" @selected((int) old('application_id') === $a->id)>{{ \Illuminate\Support\Str::limit($a->job->title.' · '.$a->job->company, 50) }}</option>@endforeach</select></x-field>
                <x-field label="Time zone of the time below" name="source_timezone" hint="Use the zone the recruiter wrote. We convert to UTC for storage.">
                    <select id="f-source_timezone" name="source_timezone" class="input">@foreach ($timezones as $tz)<option value="{{ $tz }}" @selected(old('source_timezone', user_tz()) === $tz)>{{ $tz }}</option>@endforeach</select>
                </x-field>
                <x-field label="Starts" name="starts_at_local"><input id="f-starts_at_local" type="datetime-local" name="starts_at_local" class="input" value="{{ old('starts_at_local') }}"></x-field>
                <x-field label="Ends (optional)" name="ends_at_local"><input id="f-ends_at_local" type="datetime-local" name="ends_at_local" class="input" value="{{ old('ends_at_local') }}"></x-field>
                <x-field label="Deadline (assessments)" name="deadline_local"><input id="f-deadline_local" type="datetime-local" name="deadline_local" class="input" value="{{ old('deadline_local') }}"></x-field>
                <x-field label="Meeting link" name="meeting_url"><input id="f-meeting_url" type="url" name="meeting_url" class="input" value="{{ old('meeting_url') }}" placeholder="https://…"></x-field>
                <x-field label="Notes" name="notes"><textarea id="f-notes" name="notes" rows="2" class="input">{{ old('notes') }}</textarea></x-field>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" class="check" name="state" value="confirmed" @checked(old('state') === 'confirmed')> Already confirmed with the other side</label>
                <button class="btn btn-primary w-full">Save</button>
            </form>
        </aside>
    </div>
</x-layouts.app>
