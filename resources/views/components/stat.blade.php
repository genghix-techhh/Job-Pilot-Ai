@props(['label', 'value', 'icon' => 'chart', 'href' => null, 'note' => null, 'tone' => 'slate'])
@php
    $tint = ['slate' => 'bg-slate-100 text-slate-600', 'amber' => 'bg-amber-50 text-amber-600', 'emerald' => 'bg-emerald-50 text-emerald-600', 'sky' => 'bg-sky-50 text-sky-600', 'violet' => 'bg-violet-50 text-violet-600'][$tone] ?? 'bg-slate-100 text-slate-600';
    $tag = $href ? 'a' : 'div';
@endphp
<{{ $tag }} @if ($href) href="{{ $href }}" @endif class="card flex items-center gap-4 p-4 {{ $href ? 'transition hover:border-amber-300 hover:shadow' : '' }}">
    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg {{ $tint }}"><x-icon :name="$icon" class="h-6 w-6" /></div>
    <div class="min-w-0">
        <p class="text-2xl font-bold tabular-nums leading-none text-slate-900">{{ $value }}</p>
        <p class="mt-1 text-sm leading-snug text-slate-600">{{ $label }}</p>
        @if ($note)<p class="text-xs leading-snug text-slate-400">{{ $note }}</p>@endif
    </div>
</{{ $tag }}>
