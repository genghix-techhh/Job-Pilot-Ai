@props(['value' => null, 'tier' => 'unscored', 'size' => 48])
{{-- Circular score meter. Colour follows the match tier; the number is always printed (never colour-only). --}}
@php
    $has = $value !== null;
    $pct = $has ? max(0, min(100, (float) $value)) : 0;
    $r = 20; $c = 2 * M_PI * $r;
    $stroke = match ($tier) { 'strong' => '#059669', 'potential' => '#d97706', default => '#94a3b8' };
@endphp
<div class="relative inline-flex shrink-0 items-center justify-center" style="width: {{ $size }}px; height: {{ $size }}px"
     role="img" aria-label="{{ $has ? 'Match score '.round($pct).' out of 100' : 'Not scored yet' }}">
    <svg viewBox="0 0 48 48" class="h-full w-full -rotate-90" aria-hidden="true">
        <circle cx="24" cy="24" r="{{ $r }}" fill="none" stroke="#e2e8f0" stroke-width="5" />
        @if ($has)
            <circle cx="24" cy="24" r="{{ $r }}" fill="none" stroke="{{ $stroke }}" stroke-width="5" stroke-linecap="round"
                    stroke-dasharray="{{ round($c, 2) }}" stroke-dashoffset="{{ round($c * (1 - $pct / 100), 2) }}" />
        @endif
    </svg>
    <span class="absolute text-[13px] font-bold tabular-nums text-slate-800">{{ $has ? round($pct) : '–' }}</span>
</div>
