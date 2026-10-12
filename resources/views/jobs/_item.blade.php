@php
    $tier = \App\Enums\MatchTier::tryFrom((string) ($job->match_tier ?? '')) ?? \App\Enums\MatchTier::Unscored;
    $elig = \App\Enums\EligibilityStatus::tryFrom((string) ($job->match_eligibility ?? ''));
    $matched = json_decode((string) ($job->match_matched_skills ?? '[]'), true) ?: [];
    $posted = $job->posted_at ?? $job->first_seen_at;
@endphp
<article class="card flex flex-col p-4 transition hover:border-amber-300 hover:shadow">
    <div class="flex items-start gap-3">
        <input type="checkbox" form="bulk-form" name="ids[]" value="{{ $job->id }}" class="check mt-1" aria-label="Select {{ $job->title }}">
        <x-score :value="$job->match_score ?? null" :tier="$tier->value" />
        <div class="min-w-0 flex-1">
            <h3 class="font-semibold leading-snug text-slate-900">
                <a href="{{ route('jobs.show', $job) }}" class="hover:text-amber-700 hover:underline">{{ $job->title }}</a>
            </h3>
            <p class="mt-0.5 truncate text-sm text-slate-600">{{ $job->company ?: 'Company not stated' }}</p>
        </div>
        @if ($job->user_status === 'saved')<x-icon name="bookmark" class="h-5 w-5 text-amber-500" />@endif
    </div>

    <div class="mt-3 flex flex-wrap gap-1.5">
        @if ($tier !== \App\Enums\MatchTier::Unscored)<x-badge :tone="$tier->tone()" dot>{{ $tier->label() }}</x-badge>@endif
        @if ($elig)<x-badge :tone="$elig->tone()">{{ $elig->label() }}</x-badge>@endif
        @if ($job->kind === \App\Enums\JobKind::Freelance)<x-badge tone="violet">Freelance</x-badge>@endif
        @if ($job->is_remote)<x-badge tone="sky">Remote</x-badge>@endif
        @if (! empty($job->match_exclusion))<x-badge tone="rose" title="{{ $job->match_exclusion }}">Excluded by your rules</x-badge>@endif
        @if ($job->needs_details)<x-badge tone="amber">Needs details</x-badge>@endif
        @if ($job->is_manual)<x-badge>Manual</x-badge>@endif
    </div>

    @if ($matched)
        <p class="mt-3 line-clamp-2 text-xs text-slate-500"><span class="font-medium text-slate-600">Matches:</span> {{ implode(', ', array_slice($matched, 0, 6)) }}</p>
    @endif

    <dl class="mt-3 grid grid-cols-2 gap-x-3 gap-y-1 text-xs text-slate-500">
        <div class="truncate"><dt class="sr-only">Location</dt>{{ $job->location ?: 'Location not stated' }}</div>
        <div class="truncate text-right"><dt class="sr-only">Posted</dt>{{ jp_date($posted) }}</div>
        <div class="truncate"><dt class="sr-only">Source</dt>via {{ $job->source->name }}</div>
        <div class="text-right font-medium text-slate-600"><dt class="sr-only">Salary</dt>{{ $job->salaryLabel() ?? '' }}</div>
    </dl>

    <div class="mt-4 flex items-center justify-between gap-2 border-t border-slate-100 pt-3">
        <a href="{{ route('jobs.show', $job) }}" class="btn btn-secondary btn-sm">View details</a>
        <div class="flex items-center gap-1">
            <form method="POST" action="{{ route('jobs.status', $job) }}">@csrf @method('PATCH')
                <input type="hidden" name="action" value="{{ $job->user_status === 'saved' ? 'unsave' : 'save' }}">
                <button class="btn btn-ghost btn-sm" title="{{ $job->user_status === 'saved' ? 'Remove from saved' : 'Save for later' }}" aria-label="{{ $job->user_status === 'saved' ? 'Remove from saved' : 'Save for later' }}"><x-icon name="bookmark" class="h-4 w-4" /></button>
            </form>
            <form method="POST" action="{{ route('jobs.status', $job) }}">@csrf @method('PATCH')
                <input type="hidden" name="action" value="{{ $job->user_status === 'hidden' ? 'unhide' : 'hide' }}">
                <button class="btn btn-ghost btn-sm" title="{{ $job->user_status === 'hidden' ? 'Unhide' : 'Hide from feed' }}" aria-label="{{ $job->user_status === 'hidden' ? 'Unhide' : 'Hide from feed' }}"><x-icon name="eye-off" class="h-4 w-4" /></button>
            </form>
        </div>
    </div>
</article>
