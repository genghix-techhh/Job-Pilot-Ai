<x-layouts.app title="Add a job link" max="max-w-3xl">
    <x-page-header title="Add a job link" subtitle="Paste a posting you found yourself — on LinkedIn, Indeed, Upwork or anywhere. JobPilot never crawls the link: it only stores it, and scores what you paste." />

    <div class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900">
        <p class="font-semibold">About restricted sites</p>
        <p class="mt-1">LinkedIn, Indeed, Upwork and Fiverr do not allow automated access, so JobPilot does not scrape or auto-apply there. Import the posting here, get a draft, then apply on the site yourself.</p>
    </div>

    <form method="POST" action="{{ route('jobs.store') }}" class="card card-pad space-y-5" novalidate>
        @csrf
        <x-field label="Link to the posting" name="url" hint="Required. Must start with http:// or https://">
            <input id="f-url" type="url" name="url" value="{{ old('url') }}" class="input" placeholder="https://…" required>
        </x-field>
        <p class="text-sm text-slate-500">Everything below is optional — but without a title and description the job cannot be scored; it is saved as <em>“needs details”</em>.</p>
        <div class="grid gap-4 sm:grid-cols-2">
            <x-field label="Job title" name="title"><input id="f-title" name="title" value="{{ old('title') }}" class="input"></x-field>
            <x-field label="Company / client" name="company"><input id="f-company" name="company" value="{{ old('company') }}" class="input"></x-field>
            <x-field label="Location" name="location"><input id="f-location" name="location" value="{{ old('location') }}" class="input" placeholder="e.g. Remote — Europe"></x-field>
            <x-field label="Type" name="kind">
                <select id="f-kind" name="kind" class="input">
                    <option value="employment" @selected(old('kind') !== 'freelance')>Employment</option>
                    <option value="freelance" @selected(old('kind') === 'freelance')>Freelance project</option>
                </select>
            </x-field>
        </div>
        <x-field label="Description" name="description" hint="Paste the posting text (plain text is fine).">
            <textarea id="f-description" name="description" rows="9" class="input">{{ old('description') }}</textarea>
        </x-field>
        <div class="grid gap-4 sm:grid-cols-3">
            <x-field label="Salary / budget min" name="salary_min"><input id="f-salary_min" type="number" min="0" name="salary_min" value="{{ old('salary_min') }}" class="input"></x-field>
            <x-field label="Salary / budget max" name="salary_max"><input id="f-salary_max" type="number" min="0" name="salary_max" value="{{ old('salary_max') }}" class="input"></x-field>
            <x-field label="Currency" name="salary_currency"><input id="f-salary_currency" name="salary_currency" maxlength="3" value="{{ old('salary_currency', 'USD') }}" class="input uppercase"></x-field>
        </div>
        <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" class="check" name="remote" value="1" @checked(old('remote'))> This is a remote position</label>
        <div class="flex gap-2">
            <button class="btn btn-primary">Save job</button>
            <a href="{{ route('jobs.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</x-layouts.app>
