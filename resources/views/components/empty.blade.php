@props(['icon' => 'info', 'title', 'text' => null])
<div {{ $attributes->merge(['class' => 'rounded-xl border border-dashed border-slate-300 bg-white px-6 py-10 text-center']) }}>
    <div class="mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-amber-50 text-amber-600 ring-1 ring-amber-200">
        <x-icon :name="$icon" class="h-6 w-6" />
    </div>
    <h3 class="mt-3 text-sm font-semibold text-slate-900">{{ $title }}</h3>
    @if ($text)<p class="mx-auto mt-1 max-w-md text-sm text-slate-500">{{ $text }}</p>@endif
    @if (trim($slot))<div class="mt-4 flex flex-wrap items-center justify-center gap-2">{{ $slot }}</div>@endif
</div>
