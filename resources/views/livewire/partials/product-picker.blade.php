{{--
    Barcode-first product picker. NEVER wrap this partial in a <form>: browsers
    drop nested form tags, so Enter would submit the outer form instead of
    scanning. The scan input handles Enter itself; the Add button serves touch
    devices without a hardware scanner.
--}}
<div class="grid gap-3 md:grid-cols-2"
     x-data
     x-on:scan-focus.window="$nextTick(() => { $refs.scan && $refs.scan.focus() })">
    <div>
        <label for="scan" class="form-label">{{ __('Scan barcode') }}</label>
        <div class="flex gap-2">
            <input id="scan" x-ref="scan" data-barcode-scan type="text"
                   wire:model="scan"
                   wire:keydown.enter.prevent="scanBarcode"
                   enterkeyhint="enter" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false"
                   class="form-input min-w-0 flex-1 font-mono text-lg"
                   placeholder="{{ __('Scan or type a barcode, then Enter') }}"
                   aria-describedby="scan-feedback">
            <button type="button" wire:click="scanBarcode" wire:loading.attr="disabled" wire:target="scanBarcode" class="btn btn-secondary shrink-0">{{ __('Add') }}</button>
        </div>
        <div id="scan-feedback" aria-live="polite">
            @error('scan') <p class="form-error">{{ $message }}</p> @enderror
            @if ($scanMessage !== '' && ! $errors->has('scan'))
                <p class="mt-1.5 text-sm text-brand-700 dark:text-brand-100">{{ $scanMessage }}</p>
            @endif
            <p wire:loading wire:target="scanBarcode" class="mt-1.5 text-xs text-slate-500">{{ __('Looking up…') }}</p>
        </div>
    </div>
    <div class="relative">
        <label for="product-search" class="form-label">{{ __('Search products') }}</label>
        <input id="product-search" type="search" wire:model.live.debounce.300ms="productSearch" class="form-input" placeholder="{{ __('Name or SKU') }}" autocomplete="off">
        @error('productSearch') <p class="form-error">{{ $message }}</p> @enderror
        @if ($results->isNotEmpty())
            <ul class="absolute z-20 mt-1 max-h-72 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-lg dark:border-slate-700 dark:bg-slate-800">
                @foreach ($results as $result)
                    <li>
                        <button type="button" wire:click="pickProduct({{ $result->id }})" class="flex min-h-12 w-full items-center justify-between gap-3 px-4 py-3 text-left text-sm hover:bg-slate-50 dark:hover:bg-slate-700">
                            <span class="font-medium">{{ $result->name }}</span>
                            <span class="text-xs text-slate-500">{{ $result->sku }} · {{ __($result->baseUnit->name) }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
