@props(['label', 'for', 'error' => null, 'hint' => null])
<div {{ $attributes }}>
    <label for="{{ $for }}" class="form-label">{{ $label }}</label>
    {{ $slot }}
    @if ($hint)
        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $hint }}</p>
    @endif
    @error($error ?? $for)
        <p class="form-error">{{ $message }}</p>
    @enderror
</div>
