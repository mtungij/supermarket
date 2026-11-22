@props(['placeholder' => null])
<div class="relative min-w-0 flex-1">
    <label class="sr-only" for="{{ $attributes->get('id', 'search') }}">{{ __('Search') }}</label>
    <input type="search" {{ $attributes->merge(['id' => 'search', 'class' => 'form-input pr-10']) }} placeholder="{{ $placeholder ?? __('Search') }}" autocomplete="off">
    <span wire:loading.delay class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-slate-400">…</span>
</div>
