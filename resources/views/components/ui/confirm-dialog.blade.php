{{--
    Reusable, accessible confirmation dialog (centred), controlled by a
    Livewire boolean:

        <x-ui.confirm-dialog wire:model="confirmingPost" confirm="post"
            :title="__('Post opening stock?')" :description="__('…')"
            :confirm-label="__('Confirm posting')" :busy-label="__('Posting…')">
            …summary…
        </x-ui.confirm-dialog>

    - role="alertdialog", aria-modal, labelled + described
    - focus is trapped inside (Alpine x-trap: background aria-hidden, no
      page scroll); on close focus returns to `return-focus` (the button
      that opened it - needed because Livewire disables that button while
      the server check runs, which drops focus)
    - Escape, Cancel and the backdrop close it (never while the action runs)
    - the confirm button calls the Livewire action `confirm`; it is
      disabled + shows a busy label while the request runs, so a double
      click cannot fire twice. The SERVER action stays the safeguard.
    - the body scrolls on short screens; works from 320 px, light/dark
--}}
@props([
    'title',
    'confirm',
    'description' => null,
    'confirmLabel' => null,
    'busyLabel' => null,
    'cancelLabel' => null,
    'tone' => 'primary',       // primary | danger
    'maxWidth' => 'max-w-lg',
    'disabled' => false,
    'returnFocus' => null,     // CSS selector focused after closing (e.g. '[data-post]')
])
@php($dialogId = 'dialog-'.\Illuminate\Support\Str::random(8))
<div x-data="{ open: @entangle($attributes->wire('model')), busy: false }"
     x-cloak x-show="open"
     x-on:keydown.escape.window="if (open && ! busy) open = false"
     x-effect="if (! open) busy = false"
     x-init="
        $watch('open', (value) => { if (! value && {{ $returnFocus ? 'true' : 'false' }}) setTimeout(() => document.querySelector(@js($returnFocus))?.focus(), 30) });
        Livewire.hook('request', ({ succeed, fail }) => { succeed(() => $nextTick(() => busy = false)); fail(() => busy = false) })"
     class="fixed inset-0 z-[60] flex items-center justify-center p-4 sm:p-6"
     data-confirm-dialog="{{ $confirm }}">
    <div x-show="open" x-transition.opacity.duration.150ms x-on:click="if (! busy) open = false"
         class="absolute inset-0 bg-slate-950/60 backdrop-blur-[2px]" aria-hidden="true"></div>

    <div x-show="open" x-trap.inert.noscroll="open"
         x-transition:enter="transition duration-150 ease-out" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
         role="alertdialog" aria-modal="true" aria-labelledby="{{ $dialogId }}-title" @if ($description) aria-describedby="{{ $dialogId }}-desc" @endif
         class="relative flex max-h-[calc(100dvh-2rem)] w-full {{ $maxWidth }} flex-col overflow-hidden rounded-2xl bg-white text-slate-900 shadow-2xl ring-1 ring-black/5 dark:bg-slate-800 dark:text-slate-100 dark:ring-white/10"
         data-dialog-panel>
        {{-- header + body scroll together on short screens; the buttons stay visible --}}
        <div class="min-h-0 flex-1 overflow-y-auto" data-dialog-body>
        <div class="flex items-start gap-4 px-5 pt-5 sm:px-6 sm:pt-6">
            <span @class(['grid size-11 shrink-0 place-items-center rounded-full',
                'bg-brand-50 text-brand-700 dark:bg-brand-900/40 dark:text-brand-200' => $tone !== 'danger',
                'bg-red-50 text-red-600 dark:bg-red-950/50 dark:text-red-300' => $tone === 'danger'])>
                <x-icon :name="$tone === 'danger' ? 'x' : 'shield'" />
            </span>
            <div class="min-w-0">
                <h2 id="{{ $dialogId }}-title" class="text-lg font-semibold leading-snug">{{ $title }}</h2>
                @if ($description)<p id="{{ $dialogId }}-desc" class="mt-1 text-sm text-slate-600 dark:text-slate-300">{{ $description }}</p>@endif
            </div>
        </div>

        <div class="px-5 py-4 sm:px-6">
            {{ $slot }}
        </div>
        </div>

        <div class="flex gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3 sm:justify-end sm:px-6 sm:py-4 dark:border-slate-700 dark:bg-slate-900/40">
            <button type="button" x-on:click="open = false" x-bind:disabled="busy" class="btn btn-secondary min-w-0 flex-1 px-3 sm:flex-none sm:px-5" data-dialog-cancel>{{ $cancelLabel ?? __('Cancel') }}</button>
            <button type="button" wire:click="{{ $confirm }}" x-on:click="busy = true"
                    x-bind:disabled="busy" @disabled($disabled)
                    @class(['btn min-w-0 flex-1 px-3 sm:flex-none sm:px-5', 'btn-danger' => $tone === 'danger', 'btn-primary' => $tone !== 'danger'])
                    x-bind:aria-busy="busy.toString()" data-dialog-confirm>
                <span x-show="! busy">{{ $confirmLabel ?? __('Confirm') }}</span>
                <span x-show="busy" class="inline-flex items-center gap-2">
                    <svg class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".25" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                    {{ $busyLabel ?? __('Working…') }}
                </span>
            </button>
        </div>
    </div>
</div>
