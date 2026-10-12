<x-layouts.guest title="Create owner">
    <h1 class="text-xl font-bold text-slate-900">Create the owner account</h1>
    <p class="mt-1 text-sm text-slate-500">One-time setup. This page closes permanently once the owner exists.</p>

    <form method="POST" action="{{ route('setup.store') }}" class="mt-6 space-y-4" novalidate>
        @csrf
        <x-field label="Setup token" name="token" hint="The OWNER_SETUP_TOKEN value from your server's .env file.">
            <input id="f-token" name="token" type="password" class="input" autocomplete="off" required>
        </x-field>
        <x-field label="Your name" name="name"><input id="f-name" name="name" value="{{ old('name') }}" class="input" required></x-field>
        <x-field label="Login e-mail" name="email"><input id="f-email" name="email" type="email" value="{{ old('email') }}" class="input" autocomplete="username" required></x-field>
        <x-field label="Password" name="password" hint="At least 12 characters.">
            <input id="f-password" name="password" type="password" class="input" autocomplete="new-password" required>
        </x-field>
        <x-field label="Repeat password" name="password_confirmation">
            <input id="f-password_confirmation" name="password_confirmation" type="password" class="input" autocomplete="new-password" required>
        </x-field>
        <x-field label="Your time zone" name="timezone" hint="All times are stored in UTC and shown in this zone.">
            <select id="f-timezone" name="timezone" class="input">
                @foreach ($timezones as $tz)
                    <option value="{{ $tz }}" @selected(old('timezone', config('jobpilot.timezone')) === $tz)>{{ $tz }}</option>
                @endforeach
            </select>
        </x-field>
        <button type="submit" class="btn btn-primary w-full">Create owner</button>
    </form>
</x-layouts.guest>
