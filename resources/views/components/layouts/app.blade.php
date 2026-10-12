@props(['title' => null, 'max' => 'max-w-7xl'])
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · ' : '' }}JobPilot AI</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full" x-data="{ nav: false }" @keydown.escape.window="nav = false">
<a href="#main" class="sr-only z-50 rounded-lg bg-white px-3 py-2 text-sm font-semibold shadow focus:not-sr-only focus:fixed focus:left-3 focus:top-3">Skip to content</a>

{{-- Mobile top bar --}}
<header class="sticky top-0 z-30 flex items-center justify-between border-b border-slate-200 bg-white/95 px-4 py-3 backdrop-blur lg:hidden">
    <a href="{{ route('dashboard') }}" class="flex items-center gap-2 font-bold text-slate-900">
        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-slate-900 text-amber-400"><x-icon name="sparkles" class="h-5 w-5" /></span>
        JobPilot <span class="text-amber-600">AI</span>
    </a>
    <button type="button" class="btn btn-secondary btn-sm" @click="nav = true" aria-label="Open menu" :aria-expanded="nav.toString()" data-no-lock>
        <x-icon name="menu" class="h-5 w-5" />
    </button>
</header>

{{-- Mobile drawer --}}
<div x-show="nav" x-cloak class="fixed inset-0 z-40 lg:hidden" role="dialog" aria-modal="true" aria-label="Menu">
    <div class="absolute inset-0 bg-slate-900/50" @click="nav = false" x-transition.opacity></div>
    <aside class="relative flex h-full w-72 max-w-[85%] flex-col bg-white shadow-xl" x-show="nav" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0">
        <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
            <span class="font-bold text-slate-900">Menu</span>
            <button type="button" class="btn btn-ghost btn-sm" @click="nav = false" aria-label="Close menu" data-no-lock><x-icon name="x" class="h-5 w-5" /></button>
        </div>
        @include('partials.nav')
    </aside>
</div>

{{-- Desktop sidebar --}}
<aside class="hidden border-r border-slate-200 bg-white lg:fixed lg:inset-y-0 lg:flex lg:w-64 lg:flex-col">
    <a href="{{ route('dashboard') }}" class="flex items-center gap-3 border-b border-slate-200 px-5 py-4">
        <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-slate-900 text-amber-400 shadow-sm"><x-icon name="sparkles" class="h-5 w-5" /></span>
        <span class="text-lg font-bold tracking-tight text-slate-900">JobPilot <span class="text-amber-600">AI</span></span>
    </a>
    @include('partials.nav')
</aside>

<div class="lg:pl-64">
    <main id="main" class="mx-auto {{ $max }} space-y-6 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <x-flash />
        {{ $slot }}
    </main>
    <footer class="mx-auto {{ $max }} px-4 pb-8 text-xs text-slate-400 sm:px-6 lg:px-8">
        JobPilot AI · private installation ·
        <a class="underline decoration-slate-300 underline-offset-2 hover:text-slate-600" href="{{ route('privacy.show') }}">Privacy &amp; data</a>
        · All times shown in {{ user_tz() }}
    </footer>
</div>
</body>
</html>
