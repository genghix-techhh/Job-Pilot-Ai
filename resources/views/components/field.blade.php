@props(['label', 'name', 'hint' => null, 'for' => null])
{{-- Label + control (slot) + hint + validation error. $name is the dotted validation key. --}}
@php($id = $for ?? 'f-'.str_replace(['.', '[', ']'], '-', $name))
<div {{ $attributes->only('class') }}>
    <label for="{{ $id }}" class="label">{{ $label }}</label>
    {{ $slot }}
    @if ($hint)<p class="hint">{{ $hint }}</p>@endif
    @error($name)<p class="error" role="alert">{{ $message }}</p>@enderror
</div>
