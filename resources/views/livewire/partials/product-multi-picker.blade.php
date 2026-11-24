{{--
    Searchable multi-select product picker (App\Livewire\Concerns\PicksManyProducts).
    Include inside a Livewire component that uses the trait; pass $picker = $this->pickerResults().
    Keyboard: type to search · ↓/↑ move through results · Space/Enter tick ·
    Ctrl+Enter add selected · Esc close. Full screen on phones, centred on desktop.
--}}
@php($selectedCount = count($pickerSelected))
<div class="flex flex-wrap items-center gap-2">
    <button type="button" wire:click="openPicker" class="btn btn-primary" data-open-picker aria-haspopup="dialog">
        <x-icon name="search" class="size-5" /> {{ __('Select products') }}
    </button>
    @if ($pickerMessage !== '' && ! $pickerOpen)<p class="text-sm text-brand-700 dark:text-brand-200" role="status" data-picker-message>{{ $pickerMessage }}</p>@endif
</div>

@if ($pickerOpen)
    <div class="fixed inset-0 z-[60] flex items-stretch justify-center sm:items-center sm:p-6" data-product-picker
         x-data="{
            rows() { return [...$el.querySelectorAll('[data-picker-row] input[type=checkbox]:not(:disabled)')] },
            move(step) { const r = this.rows(); if (! r.length) return; const i = r.indexOf(document.activeElement); r[Math.max(0, Math.min(r.length - 1, i + step))].focus() },
         }"
         x-on:keydown.escape.prevent.stop="$wire.closePicker()"
         x-on:keydown.ctrl.enter.prevent="$wire.addPicked()" x-on:keydown.meta.enter.prevent="$wire.addPicked()">
        <div class="absolute inset-0 bg-slate-950/60 backdrop-blur-[2px]" wire:click="closePicker" aria-hidden="true"></div>
        <div x-trap.noscroll="true" role="dialog" aria-modal="true" aria-labelledby="picker-title"
             class="relative flex h-dvh w-full flex-col bg-white shadow-2xl sm:h-auto sm:max-h-[min(44rem,calc(100dvh-3rem))] sm:max-w-3xl sm:rounded-2xl dark:bg-slate-800">
            <div class="flex items-center gap-3 border-b border-slate-200 px-4 py-3 dark:border-slate-700">
                <h2 id="picker-title" class="flex-1 text-lg font-semibold">{{ __('Select products') }}</h2>
                <button type="button" wire:click="closePicker" class="grid size-11 place-items-center rounded-xl text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-700" aria-label="{{ __('Close') }}"><x-icon name="x" /></button>
            </div>

            <div class="border-b border-slate-200 p-4 dark:border-slate-700">
                <label for="picker-search" class="sr-only">{{ __('Search products') }}</label>
                <div class="relative">
                    <x-icon name="search" class="pointer-events-none absolute left-3.5 top-1/2 size-5 -translate-y-1/2 text-slate-400" />
                    <input id="picker-search" type="search" wire:model.live.debounce.250ms="pickerSearch" autofocus autocomplete="off" spellcheck="false"
                           x-on:keydown.arrow-down.prevent="move(1)" x-on:keydown.enter.prevent="move(0)"
                           class="form-input h-12 pl-11" placeholder="{{ __('Name, SKU or barcode') }}" aria-describedby="picker-help">
                    <span wire:loading wire:target="pickerSearch,showMorePicker" class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-slate-500">{{ __('Searching…') }}</span>
                </div>
                <p id="picker-help" class="mt-2 hidden text-xs text-slate-500 sm:block">{{ __('↑ ↓ to move · Space to tick · Ctrl+Enter to add · Esc to close') }}</p>
                @if ($pickerMessage !== '')<p class="mt-2 text-sm text-amber-800 dark:text-amber-200" role="alert">{{ $pickerMessage }}</p>@endif
            </div>

            <ul class="min-h-0 flex-1 divide-y divide-slate-100 overflow-y-auto overscroll-contain dark:divide-slate-700" role="list" data-picker-list
                x-on:keydown.arrow-down.prevent="move(1)" x-on:keydown.arrow-up.prevent="rows().indexOf(document.activeElement) === 0 ? document.getElementById('picker-search').focus() : move(-1)">
                @forelse ($picker['rows'] as $p)
                    @php($added = isset($picker['inList'][$p->id]))
                    @php($ticked = isset($pickerSelected[$p->id]))
                    <li wire:key="pick-{{ $p->id }}" data-picker-row>
                        <label @class(['flex min-h-16 cursor-pointer items-center gap-3 px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-700/40',
                            'bg-brand-50/70 dark:bg-brand-900/20' => $ticked, 'cursor-default opacity-60' => $added])>
                            <input type="checkbox" class="size-5 shrink-0 rounded border-slate-300 accent-brand-600" @checked($ticked || $added) @disabled($added)
                                   wire:click="togglePicked({{ $p->id }})" x-on:keydown.enter.prevent="$el.click()" aria-describedby="pick-meta-{{ $p->id }}">
                            <span class="min-w-0 flex-1">
                                <span class="block font-semibold leading-snug [overflow-wrap:anywhere]">{{ $p->name }}</span>
                                <span id="pick-meta-{{ $p->id }}" class="block text-xs text-slate-500 [overflow-wrap:anywhere]">{{ $p->sku }}@if ($p->barcodes->first()) · <span class="font-mono">{{ $p->barcodes->first()->barcode }}</span>@endif · {{ __($p->baseUnit->name) }}</span>
                            </span>
                            <span class="shrink-0 text-right text-xs">
                                @if ($added)<span class="rounded-full bg-slate-200 px-2 py-0.5 font-semibold text-slate-700 dark:bg-slate-600 dark:text-slate-100">{{ __('Added') }}</span>
                                @elseif ($picker['showStock'])
                                    <span class="block text-slate-500">{{ __('In stock') }}</span><span class="font-semibold tabular-nums">{{ $picker['stock'][$p->id] ?? '0' }}</span>
                                @endif
                            </span>
                        </label>
                    </li>
                @empty
                    <li class="px-4 py-12 text-center text-sm text-slate-500" data-picker-empty>
                        <span wire:loading.remove wire:target="pickerSearch">{{ $pickerSearch === '' ? __('Your catalogue has no active products yet.') : __('No products match ":term".', ['term' => $pickerSearch]) }}</span>
                    </li>
                @endforelse
                @if ($picker['more'])
                    <li class="p-3 text-center"><button type="button" wire:click="showMorePicker" class="btn btn-secondary">{{ __('Show more') }}</button></li>
                @endif
            </ul>

            <div class="flex items-center gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] dark:border-slate-700 dark:bg-slate-900/40">
                <p class="flex-1 text-sm" aria-live="polite" data-picker-count><span class="font-semibold">{{ $selectedCount }}</span> {{ trans_choice('product selected|products selected', $selectedCount) }}</p>
                @if ($selectedCount)<button type="button" wire:click="clearPicked" class="btn btn-secondary px-3">{{ __('Clear') }}</button>@endif
                <button type="button" wire:click="addPicked" wire:loading.attr="disabled" wire:target="addPicked" @disabled($selectedCount === 0) class="btn btn-primary" data-add-picked>
                    {{ __('Add selected') }}@if ($selectedCount) ({{ $selectedCount }})@endif
                </button>
            </div>
        </div>
    </div>
@endif
