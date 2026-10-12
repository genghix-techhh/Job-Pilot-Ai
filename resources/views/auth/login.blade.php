<x-layouts.guest title="Sign in">
    <h1 class="text-xl font-bold text-slate-900">Sign in</h1>
    <p class="mt-1 text-sm text-slate-500">Private job-hunting workspace. Single owner.</p>

    @unless ($hasOwner)
        <div class="mt-5 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <p class="font-semibold">No owner account exists yet.</p>
            <p class="mt-1">On the server, run:</p>
            <pre class="mt-2 overflow-x-auto rounded bg-white/70 p-2 text-xs ring-1 ring-amber-200">php artisan app:create-owner</pre>
            @if ($setupEnabled)
                <p class="mt-2">Or use the <a href="{{ route('setup') }}" class="font-semibold underline">one-time setup page</a> (needs your OWNER_SETUP_TOKEN).</p>
            @endif
        </div>
    @endunless

    <form method="POST" action="{{ route('login.store') }}" class="mt-6 space-y-4" novalidate>
        @csrf
        <x-field label="E-mail" name="email">
            <input id="f-email" name="email" type="email" value="{{ old('email') }}" class="input" autocomplete="username" required autofocus>
        </x-field>
        <x-field label="Password" name="password">
            <input id="f-password" name="password" type="password" class="input" autocomplete="current-password" required>
        </x-field>
        <label class="flex items-center gap-2 text-sm text-slate-600">
            <input type="checkbox" name="remember" value="1" class="check"> Keep me signed in on this device
        </label>
        <button type="submit" class="btn btn-primary w-full">Sign in</button>
    </form>
</x-layouts.guest>
