@props(['label', 'value', 'max' => 100, 'tone' => 'amber', 'suffix' => '', 'note' => null])
{{-- One horizontal bar. The number is always printed, so colour is never the only signal. --}}
@php
    $pct = $max > 0 ? max(0, min(100, $value / $max * 100)) : 0;
    $fill = ['amber' => 'bg-amber-500', 'emerald' => 'bg-emerald-500', 'rose' => 'bg-rose-400', 'slate' => 'bg-slate-400', 'sky' => 'bg-sky-500'][$tone] ?? 'bg-amber-500';
@endphp
<div class="grid grid-cols-[minmax(0,9rem)_1fr_auto] items-center gap-3 text-sm sm:grid-cols-[minmax(0,12rem)_1fr_auto]">
    <div class="truncate text-slate-700" title="{{ $label }}">{{ $label }}</div>
    <div class="h-2.5 overflow-hidden rounded-full bg-slate-100" role="img" aria-label="{{ $label }}: {{ $value }}{{ $suffix }}">
        <div class="h-full rounded-full {{ $fill }}" style="width: {{ round($pct, 1) }}%"></div>
    </div>
    <div class="min-w-[3.5rem] text-right tabular-nums text-slate-600">{{ $value }}{{ $suffix }}@if ($note)<span class="ml-1 text-xs text-slate-400">{{ $note }}</span>@endif</div>
</div>
