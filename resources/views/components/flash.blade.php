{{-- Session flash + validation summary. Always escaped. --}}
@if (session('status'))
    <div class="flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900" role="status">
        <x-icon name="check-circle" class="mt-0.5 h-5 w-5 text-emerald-600" />
        <p>{{ session('status') }}</p>
    </div>
@endif
@if (session('error'))
    <div class="flex items-start gap-3 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900" role="alert">
        <x-icon name="warning" class="mt-0.5 h-5 w-5 text-rose-600" />
        <p>{{ session('error') }}</p>
    </div>
@endif
@if ($errors->any())
    <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900" role="alert">
        <p class="font-semibold">Please fix the following:</p>
        <ul class="mt-1 list-disc space-y-0.5 pl-5">
            @foreach (collect($errors->all())->unique()->take(8) as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif
