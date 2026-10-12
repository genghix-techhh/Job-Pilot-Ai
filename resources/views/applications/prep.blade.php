@php
    $catTone = ['technical' => 'sky', 'behavioural' => 'violet', 'role' => 'amber', 'logistics' => 'slate'];
@endphp
<x-layouts.app :title="'Interview prep · '.$job->title" max="max-w-5xl">
    <nav aria-label="Breadcrumb" class="text-sm text-slate-500">
        <a href="{{ route('applications.index') }}" class="hover:text-amber-700 hover:underline">Applications</a> <span aria-hidden="true">/</span>
        <a href="{{ route('applications.show', $app) }}" class="hover:text-amber-700 hover:underline">{{ \Illuminate\Support\Str::limit($job->title, 50) }}</a>
    </nav>

    <x-page-header :title="'Interview prep'" :subtitle="$job->title.' · '.($job->company ?: 'company not stated').'. Built from the posting and your own profile — it never invents experience for you.'">
        <x-slot name="actions">
            <button type="button" onclick="window.print()" class="btn btn-secondary" data-no-lock><x-icon name="download" class="h-4 w-4" /> Print</button>
            <a href="{{ route('applications.show', $app) }}" class="btn btn-ghost">Back to application</a>
        </x-slot>
    </x-page-header>

    <div class="flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 text-sm">
        <x-badge :tone="($plan['generator'] ?? 'local') === 'gemini' ? 'violet' : 'slate'" dot>{{ ($plan['generator'] ?? 'local') === 'gemini' ? 'Improved with Gemini ('.($plan['model'] ?? '').')' : 'Offline plan (nothing left your server)' }}</x-badge>
        @if ($ai->available)
            <form method="POST" action="{{ route('applications.prep.generate', $app) }}" x-data="confirmSubmit('Send a redacted summary of your profile and this job to Gemini (free tier; may be used by Google and reviewed by humans)?')" @submit="check($event)">@csrf
                <button class="btn btn-accent btn-sm"><x-icon name="sparkles" class="h-4 w-4" /> {{ $aiStored ? 'Regenerate with Gemini' : 'Improve with Gemini' }} (1 call)</button>
            </form>
        @else
            <span class="text-xs text-slate-500">AI is not available ({{ $ai->message }}) — the offline plan below is complete on its own.</span>
        @endif
        @if ($aiStored)
            <form method="POST" action="{{ route('applications.prep.reset', $app) }}" class="ml-auto">@csrf @method('DELETE')<button class="btn btn-ghost btn-sm">Use the offline plan</button></form>
        @endif
    </div>

    {{-- topics --}}
    <section class="card card-pad" aria-labelledby="t-h">
        <h2 id="t-h" class="h-section">What the job asks for vs. what you can show</h2>
        @if (empty($plan['topics']))
            <p class="mt-2 text-sm text-slate-500">No specific technologies were recognised in the posting.</p>
        @else
            <div class="table-wrap mt-3">
                <table class="table-base">
                    <thead><tr><th scope="col">Skill</th><th scope="col">Your profile</th><th scope="col">Evidence you can speak to</th></tr></thead>
                    <tbody>
                    @foreach ($plan['topics'] as $t)
                        <tr>
                            <td class="whitespace-nowrap font-medium text-slate-900">{{ $t['skill'] }} @unless ($t['required'])<span class="text-xs font-normal text-slate-400">nice to have</span>@endunless</td>
                            <td><x-badge :tone="$t['have'] ? 'emerald' : 'rose'">{{ $t['have'] ? 'Listed' : 'Gap' }}</x-badge></td>
                            <td class="text-slate-600">
                                @forelse ($t['evidence'] as $e)<p class="mb-1">• {{ $e }}</p>@empty<p class="text-xs text-slate-500">—</p>@endforelse
                                <p class="text-xs text-slate-500">{{ $t['advice'] }}</p>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- questions --}}
    <section aria-labelledby="q-h">
        <h2 id="q-h" class="h-section mb-3">Likely questions ({{ count($plan['questions']) }})</h2>
        <div class="space-y-2">
            @foreach ($plan['questions'] as $i => $q)
                <details class="card group p-4" @if ($i < 2) open @endif>
                    <summary class="flex cursor-pointer items-start gap-3 text-sm font-medium text-slate-900">
                        <x-badge :tone="$catTone[$q['category']] ?? 'slate'">{{ ucfirst($q['category']) }}</x-badge>
                        <span class="flex-1">{{ $q['question'] }}</span>
                    </summary>
                    <div class="mt-3 space-y-2 pl-1 text-sm text-slate-600">
                        <p><span class="font-medium text-slate-700">Why they ask:</span> {{ $q['why_asked'] }}</p>
                        @if (! empty($q['answer_outline']))
                            <p><span class="font-medium text-slate-700">How you can answer (from your evidence):</span> {{ $q['answer_outline'] }}</p>
                            @foreach ($q['evidence'] ?? [] as $e)<p class="text-xs text-slate-500">↳ {{ $e }}</p>@endforeach
                        @else
                            <p class="rounded-lg bg-slate-50 p-2 text-xs text-slate-500 ring-1 ring-inset ring-slate-200">No supporting evidence in your profile for this one. Think of a real example and, if it is true, add it to your profile — don't improvise experience.</p>
                        @endif
                    </div>
                </details>
            @endforeach
        </div>
    </section>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="card card-pad" aria-labelledby="s-h">
            <h2 id="s-h" class="h-section">Story seeds (STAR)</h2>
            <p class="mt-1 text-xs text-slate-500">Your own achievements, turned into prompts. Prepare 2–3 you can tell in two minutes each.</p>
            @forelse ($plan['stories'] as $s)
                <div class="mt-3 rounded-lg bg-slate-50 p-3 text-sm ring-1 ring-inset ring-slate-200">
                    <p class="font-medium text-slate-800">“{{ $s['seed'] }}”</p>
                    <ul class="mt-2 list-disc pl-5 text-xs text-slate-500">@foreach ($s['prompts'] as $p)<li>{{ $p }}</li>@endforeach</ul>
                </div>
            @empty
                <p class="mt-3 text-sm text-slate-500">Add achievements to your work history to get story seeds.</p>
            @endforelse
        </section>

        <div class="space-y-6">
            @if (! empty($plan['gap_plan']))
                <section class="card card-pad" aria-labelledby="g-h">
                    <h2 id="g-h" class="h-section">Closing the gaps honestly</h2>
                    <ul class="mt-3 space-y-2 text-sm">
                        @foreach ($plan['gap_plan'] as $g)<li><span class="font-semibold text-slate-900">{{ $g['skill'] }}</span> — <span class="text-slate-600">{{ $g['how_to_prepare'] }}</span></li>@endforeach
                    </ul>
                </section>
            @endif
            <section class="card card-pad" aria-labelledby="ask-h">
                <h2 id="ask-h" class="h-section">Questions to ask them</h2>
                <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-slate-700">@foreach ($plan['questions_to_ask'] as $q)<li>{{ $q }}</li>@endforeach</ul>
            </section>
            <section class="card card-pad" aria-labelledby="r-h" x-data>
                <h2 id="r-h" class="h-section">Before the interview</h2>
                <ul class="mt-3 space-y-2 text-sm text-slate-700">
                    @foreach ($plan['research'] as $r)<li class="flex gap-2"><input type="checkbox" class="check mt-0.5" aria-label="Done: {{ $r }}"><span>{{ $r }}</span></li>@endforeach
                    <li class="flex gap-2"><input type="checkbox" class="check mt-0.5"><span>Test camera, microphone and the meeting link (see Interviews).</span></li>
                </ul>
            </section>
        </div>
    </div>
</x-layouts.app>
