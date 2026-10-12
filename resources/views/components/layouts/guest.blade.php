@props(['title' => 'Sign in', 'wide' => false, 'plain' => false])
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} · JobPilot AI</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full">
<div class="mx-auto flex min-h-screen w-full {{ $wide ? 'max-w-3xl' : 'max-w-md' }} flex-col justify-center px-4 py-10 sm:px-0">
    <a href="{{ route('login') }}" class="mb-6 flex items-center justify-center gap-3">
        <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-slate-900 text-amber-400 shadow-sm"><x-icon name="sparkles" class="h-6 w-6" /></span>
        <span class="text-xl font-bold tracking-tight text-slate-900">JobPilot <span class="text-amber-600">AI</span></span>
    </a>
    <main @class(['card p-6 sm:p-8' => ! $plain])>
        {{ $slot }}
    </main>
    <p class="mt-6 text-center text-xs text-slate-500">
        A private, single-owner tool.
        <a href="{{ route('privacy.show') }}" class="underline decoration-slate-300 underline-offset-2 hover:text-slate-700">Privacy</a>
    </p>
</div>
</body>
</html>
