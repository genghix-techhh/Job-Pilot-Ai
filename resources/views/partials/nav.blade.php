@php
    $items = [
        ['dashboard', 'Dashboard', 'home', 'dashboard', null],
        ['jobs.index', 'Job Feed', 'briefcase', 'jobs.*', null],
        ['profile.edit', 'CV & Profile', 'user', 'profile.*', null],
        ['applications.index', 'Applications', 'document', 'applications.*', $navCounts['drafts'] ?? 0],
        ['interviews.index', 'Interviews', 'calendar', 'interviews.*', $navCounts['interviews'] ?? 0],
        ['inbox.index', 'Inbox Monitoring', 'mail', 'inbox.*', $navCounts['review'] ?? 0],
        ['insights.index', 'Insights', 'trend', 'insights.*', null],
        ['sources.index', 'Sources', 'globe', 'sources.*', null],
        ['settings.edit', 'Settings', 'cog', 'settings.*', null],
        ['activity.index', 'Activity & Diagnostics', 'chart', 'activity.*', null],
    ];
@endphp
<nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4" aria-label="Main">
    @foreach ($items as [$route, $label, $icon, $pattern, $count])
        <a href="{{ route($route) }}" class="nav-link group" @if (request()->routeIs($pattern)) aria-current="page" @endif>
            <x-icon :name="$icon" class="h-5 w-5 text-slate-400 group-hover:text-slate-600" />
            <span class="flex-1">{{ $label }}</span>
            @if ($count)
                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold tabular-nums text-amber-800">{{ $count }}</span>
            @endif
        </a>
    @endforeach
</nav>
<div class="border-t border-slate-200 p-3">
    <div class="mb-2 flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600 ring-1 ring-inset ring-slate-200">
        <x-icon name="shield" class="h-4 w-4 text-emerald-600" />
        <span>Nothing is submitted without your approval.</span>
    </div>
    <div class="flex items-center justify-between gap-2 px-1">
        <div class="min-w-0">
            <p class="truncate text-sm font-medium text-slate-900">{{ auth()->user()->name }}</p>
            <p class="truncate text-xs text-slate-500">{{ auth()->user()->email }}</p>
        </div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn btn-ghost btn-sm" title="Sign out" aria-label="Sign out" data-no-lock>
                <x-icon name="logout" class="h-4 w-4" />
            </button>
        </form>
    </div>
</div>
