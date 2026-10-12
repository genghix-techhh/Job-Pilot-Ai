@props(['name'])
<svg {{ $attributes->merge(['class' => 'h-5 w-5 shrink-0']) }} xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" aria-hidden="true" focusable="false">
    @foreach (\App\View\Icons::paths($name) as $d)
        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $d }}" />
    @endforeach
</svg>
