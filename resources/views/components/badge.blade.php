@props(['tone' => 'slate', 'dot' => false])
@php
    $tones = [
        'slate' => 'bg-slate-100 text-slate-700 ring-slate-200',
        'amber' => 'bg-amber-50 text-amber-800 ring-amber-200',
        'emerald' => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
        'rose' => 'bg-rose-50 text-rose-800 ring-rose-200',
        'sky' => 'bg-sky-50 text-sky-800 ring-sky-200',
        'violet' => 'bg-violet-50 text-violet-800 ring-violet-200',
    ];
    $dots = ['slate' => 'bg-slate-400', 'amber' => 'bg-amber-500', 'emerald' => 'bg-emerald-500', 'rose' => 'bg-rose-500', 'sky' => 'bg-sky-500', 'violet' => 'bg-violet-500'];
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset '.($tones[$tone] ?? $tones['slate'])]) }}>
    @if ($dot)<span class="h-1.5 w-1.5 rounded-full {{ $dots[$tone] ?? $dots['slate'] }}"></span>@endif
    {{ $slot }}
</span>
