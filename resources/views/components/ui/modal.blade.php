{{-- Livewire-controlled modal: <x-ui.modal wire:model="showForm" :title="..."> --}}
@props(['title', 'maxWidth' => 'max-w-2xl'])
<div x-data="{ open: @entangle($attributes->wire('model')) }" x-cloak x-show="open" x-on:keydown.escape.window="open = false"
     class="fixed inset-0 z-50 flex items-end justify-center overflow-y-auto bg-slate-900/50 p-0 sm:items-center sm:p-6" role="dialog" aria-modal="true">
    <div x-show="open" x-transition @click.outside="open = false"
         class="w-full {{ $maxWidth }} rounded-t-2xl bg-white p-5 shadow-xl sm:rounded-2xl sm:p-6 dark:bg-slate-800">
        <div class="mb-5 flex items-start justify-between gap-4">
            <h3 class="text-lg font-semibold">{{ $title }}</h3>
            <button type="button" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-700" @click="open = false" aria-label="{{ __('Close') }}">
                <x-icon name="x" />
            </button>
        </div>
        {{ $slot }}
    </div>
</div>
