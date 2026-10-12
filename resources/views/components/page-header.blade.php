@props(['title', 'subtitle' => null])
<div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
    <div class="min-w-0">
        <h1 class="text-2xl font-bold tracking-tight text-slate-900">{{ $title }}</h1>
        @if ($subtitle)<p class="mt-1 max-w-3xl text-sm text-slate-600">{{ $subtitle }}</p>@endif
    </div>
    @if (isset($actions) && trim($actions))<div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>@endif
</div>
