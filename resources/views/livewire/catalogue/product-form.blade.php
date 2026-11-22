@use('App\Support\Decimal')
<div>
    <x-ui.page-header :title="$editing ? __('Edit product') : __('Add product')"
                      :description="$editing ? $product->name : __('Name, category, unit and selling price are enough. The product can be found in the POS as soon as it is saved.')" />
    @include('components.ui.flash')

    @if ($savedMessage !== '')
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-900 dark:border-brand-900 dark:bg-brand-900/30 dark:text-brand-100" role="status" data-saved-message>
            <span>✓ {{ $savedMessage }}</span>
            @if ($savedUuid)<a href="{{ route('catalogue.products.show', $savedUuid) }}" class="inline-flex min-h-10 items-center font-semibold underline">{{ __('View product') }}</a>@endif
        </div>
    @endif

    <form wire:submit="save('show')" class="space-y-5" novalidate>
        {{-- 1. What is it? --}}
        <section class="card space-y-5" aria-labelledby="sec-basic">
            <h2 id="sec-basic" class="text-base font-semibold">{{ __('Product details') }}</h2>
            <div class="grid gap-5 md:grid-cols-2">
                <x-ui.field class="md:col-span-2" :label="__('Product name').' *'" for="name" error="name">
                    <input id="name" type="text" wire:model="name" class="form-input text-lg" required maxlength="150" autocomplete="off"
                           @unless ($editing) autofocus @endunless x-on:product-saved.window="$nextTick(() => $el.focus())" placeholder="{{ __('e.g. DETTOL ANTISEPTIC 500ML') }}">
                </x-ui.field>

                @if ($editing && $existingBarcodes->isNotEmpty())
                    <div>
                        <p class="form-label">{{ __('Barcodes') }}</p>
                        <ul class="space-y-1 text-sm" data-existing-barcodes>
                            @foreach ($existingBarcodes as $b)
                                <li class="flex flex-wrap items-center gap-2"><span class="font-mono">{{ $b->barcode }}</span>
                                    <span class="text-xs text-slate-500">{{ __($b->productUnit->unit->name) }}@if ($b->is_primary) · {{ __('Primary') }}@endif</span></li>
                            @endforeach
                        </ul>
                        <p class="mt-1 text-xs text-slate-500">{{ __('Barcodes already used by sales and receipts are managed on') }} <a href="{{ route('catalogue.products.show', $product) }}" class="font-medium text-brand-700 underline dark:text-brand-100">{{ __('Product details') }}</a>.</p>
                    </div>
                @else
                    <x-ui.field :label="__('Barcode (optional)')" for="barcode" error="barcode" :hint="__('Scan the barcode on the product, or leave empty if it has none.')">
                        {{-- A scanner ends with Enter: move on instead of submitting the form. --}}
                        <input id="barcode" type="text" wire:model="barcode" class="form-input font-mono" autocomplete="off" autocapitalize="off" spellcheck="false"
                               inputmode="text" enterkeyhint="next" x-on:keydown.enter.prevent="document.getElementById('sku')?.focus()" placeholder="{{ __('Scan or type') }}">
                        @unless ($editing)
                            <label class="mt-2 flex min-h-10 items-center gap-2 text-sm text-slate-600 dark:text-slate-300" x-show="! $wire.barcode">
                                <input type="checkbox" wire:model="generateBarcode" class="size-5 rounded border-slate-300 text-brand-600"> {{ __('Create an internal barcode for me (to print on labels)') }}
                            </label>
                        @endunless
                    </x-ui.field>
                @endif

                <x-ui.field :label="__('SKU (optional)')" for="sku" error="sku" :hint="__('Leave empty to generate one.')">
                    <input id="sku" type="text" wire:model="sku" class="form-input uppercase" autocomplete="off" maxlength="64">
                </x-ui.field>

                {{-- Category with quick add --}}
                <div x-data="{ adding: false, msg: '' }" x-on:quick-created.window="if ($event.detail.field === 'category') { adding = false; msg = $event.detail.message }">
                    <div class="flex items-end justify-between gap-2">
                        <label for="category" class="form-label">{{ __('Category') }} *</label>
                        @if ($can['categories'])
                            <button type="button" x-on:click="adding = ! adding; $nextTick(() => adding && $refs.newCategory.focus())" class="mb-1.5 inline-flex min-h-10 items-center rounded-lg px-2 text-sm font-medium text-brand-700 hover:bg-brand-50 dark:text-brand-100 dark:hover:bg-brand-900/30" :aria-expanded="adding.toString()">+ {{ __('New category') }}</button>
                        @endif
                    </div>
                    <select id="category" wire:model="category" class="form-input" required>
                        <option value="">{{ __('Select…') }}</option>
                        @foreach ($categories as $c) <option value="{{ $c->uuid }}" wire:key="cat-{{ $c->uuid }}">{{ $c->name }}</option> @endforeach
                    </select>
                    @error('category') <p class="form-error">{{ $message }}</p> @enderror
                    <p x-show="msg" x-text="msg" x-cloak class="mt-1 text-sm text-brand-700 dark:text-brand-100" role="status"></p>
                    @if ($can['categories'])
                        <div x-show="adding" x-cloak class="mt-2 flex gap-2">
                            <label for="newCategoryName" class="sr-only">{{ __('Category name') }}</label>
                            <input id="newCategoryName" x-ref="newCategory" type="text" wire:model="newCategoryName" class="form-input min-w-0 flex-1" placeholder="{{ __('New category name') }}" x-on:keydown.enter.prevent="$wire.createCategory()">
                            <button type="button" wire:click="createCategory" wire:loading.attr="disabled" wire:target="createCategory" class="btn btn-secondary shrink-0">{{ __('Add') }}</button>
                        </div>
                        @error('newCategoryName') <p class="form-error">{{ $message }}</p> @enderror
                    @endif
                </div>

                {{-- Brand with quick add --}}
                <div x-data="{ adding: false, msg: '' }" x-on:quick-created.window="if ($event.detail.field === 'brand') { adding = false; msg = $event.detail.message }">
                    <div class="flex items-end justify-between gap-2">
                        <label for="brand" class="form-label">{{ __('Brand (optional)') }}</label>
                        @if ($can['brands'])
                            <button type="button" x-on:click="adding = ! adding; $nextTick(() => adding && $refs.newBrand.focus())" class="mb-1.5 inline-flex min-h-10 items-center rounded-lg px-2 text-sm font-medium text-brand-700 hover:bg-brand-50 dark:text-brand-100 dark:hover:bg-brand-900/30" :aria-expanded="adding.toString()">+ {{ __('New brand') }}</button>
                        @endif
                    </div>
                    <select id="brand" wire:model="brand" class="form-input">
                        <option value="">{{ __('No brand') }}</option>
                        @foreach ($brands as $b) <option value="{{ $b->uuid }}" wire:key="brand-{{ $b->uuid }}">{{ $b->name }}</option> @endforeach
                    </select>
                    @error('brand') <p class="form-error">{{ $message }}</p> @enderror
                    <p x-show="msg" x-text="msg" x-cloak class="mt-1 text-sm text-brand-700 dark:text-brand-100" role="status"></p>
                    @if ($can['brands'])
                        <div x-show="adding" x-cloak class="mt-2 flex gap-2">
                            <label for="newBrandName" class="sr-only">{{ __('Brand name') }}</label>
                            <input id="newBrandName" x-ref="newBrand" type="text" wire:model="newBrandName" class="form-input min-w-0 flex-1" placeholder="{{ __('New brand name') }}" x-on:keydown.enter.prevent="$wire.createBrand()">
                            <button type="button" wire:click="createBrand" wire:loading.attr="disabled" wire:target="createBrand" class="btn btn-secondary shrink-0">{{ __('Add') }}</button>
                        </div>
                        @error('newBrandName') <p class="form-error">{{ $message }}</p> @enderror
                    @endif
                </div>
            </div>
        </section>

        {{-- 2. How is it sold? --}}
        <section class="card space-y-5" aria-labelledby="sec-price">
            <h2 id="sec-price" class="text-base font-semibold">{{ __('Unit and price') }}</h2>
            <div class="grid gap-5 md:grid-cols-2">
                <x-ui.field :label="__('Product type')" for="type" error="productType" :hint="$structureLocked ? __('Locked: this product already has stock or purchase records.') : __('Standard = counted items. Weighed = sold by weight.')">
                    <select id="type" wire:model.live="productType" class="form-input" @disabled($structureLocked)>@foreach ($types as $t) <option value="{{ $t->value }}">{{ __($t->label()) }}</option> @endforeach</select>
                </x-ui.field>
                <x-ui.field :label="__('Unit').' *'" for="baseUnit" error="baseUnit" :hint="__('Stock is counted and sold in this unit, e.g. Piece or Kilogram.')">
                    <select id="baseUnit" wire:model.live="baseUnit" class="form-input" @disabled($structureLocked)>@foreach ($units as $u) <option value="{{ $u->uuid }}">{{ __($u->name) }} ({{ __($u->dimension->label()) }})</option> @endforeach</select>
                </x-ui.field>

                <x-ui.field :label="__('Selling price').($can['prices'] ? ' *' : '')" for="price" error="sellingPrice"
                            :hint="$can['prices'] ? __('Price per :unit for all branches. Saved in the price history.', ['unit' => $baseUnitModel ? __($baseUnitModel->name) : __('unit')]) : __('You cannot set prices. A manager must set the price before this product can be sold.')">
                    <div class="relative">
                        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm font-semibold text-slate-500">{{ $currency }}</span>
                        <input id="price" type="text" inputmode="decimal" wire:model="sellingPrice" class="form-input pl-14 text-lg font-semibold tabular-nums" autocomplete="off" @disabled(! $can['prices']) placeholder="0">
                    </div>
                </x-ui.field>
                <x-ui.field :label="__('Buying price (optional)')" for="buyingPrice" error="buyingPrice" :hint="__('For reference only (default cost on new purchase orders). Stock value comes from goods receipts.')">
                    <div class="relative">
                        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm font-semibold text-slate-500">{{ $currency }}</span>
                        <input id="buyingPrice" type="text" inputmode="decimal" wire:model="buyingPrice" class="form-input pl-14 tabular-nums" autocomplete="off" placeholder="0">
                    </div>
                </x-ui.field>

                <x-ui.field :label="__('Tax type')" for="tax" error="taxClass">
                    <select id="tax" wire:model="taxClass" class="form-input">@foreach ($taxClasses as $t) <option value="{{ $t->value }}">{{ __($t->label()) }}</option> @endforeach</select>
                </x-ui.field>
            </div>
        </section>

        {{-- 3. Advanced (collapsed by default) --}}
        <section class="card p-0" x-data="{ open: $wire.entangle('showAdvanced') }" data-advanced>
            <button type="button" x-on:click="open = ! open" :aria-expanded="open.toString()" aria-controls="advanced-panel"
                    class="flex min-h-14 w-full items-center justify-between gap-3 rounded-2xl px-5 py-4 text-left">
                <span>
                    <span class="block text-base font-semibold">{{ __('Advanced settings') }}</span>
                    <span class="block text-xs text-slate-500">{{ __('Extra barcodes, cartons/packs, stock alerts, batch & expiry tracking, description, image.') }}</span>
                </span>
                <svg class="size-5 shrink-0 transition-transform" :class="open && 'rotate-180'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
            </button>
            <div id="advanced-panel" x-show="open" x-cloak class="space-y-6 border-t border-slate-200 px-5 py-5 dark:border-slate-700">
                @if ($editing)
                    <p class="rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                        {{ __('Units, cartons/packs, extra barcodes and branch prices are managed on') }}
                        <a href="{{ route('catalogue.products.show', $product) }}" class="font-medium text-brand-700 underline dark:text-brand-100">{{ __('Product details') }}</a>{{ __(', where changes that could affect past sales and receipts are checked.') }}
                    </p>
                @else
                    <div class="grid gap-5 md:grid-cols-2">
                        <x-ui.field :label="__('Barcode type')" for="barcodeType" error="barcodeType" :hint="__('Manufacturer = printed on the package. Internal = made by your shop.')">
                            <select id="barcodeType" wire:model="barcodeType" class="form-input">@foreach ($barcodeTypes as $t) <option value="{{ $t->value }}">{{ __($t->label()) }}</option> @endforeach</select>
                        </x-ui.field>
                    </div>

                    {{-- Additional barcodes (same unit) --}}
                    <div>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h3 class="font-semibold">{{ __('Additional barcodes') }}</h3>
                            <button type="button" wire:click="addBarcodeRow" class="btn btn-secondary">+ {{ __('Add barcode') }}</button>
                        </div>
                        <p class="mt-1 text-xs text-slate-500">{{ __('Other barcodes for the same single item (e.g. an older package design).') }}</p>
                        <div class="mt-3 space-y-2">
                            @foreach ($extraBarcodes as $i => $code)
                                <div class="flex gap-2" wire:key="xb-{{ $i }}">
                                    <label for="xb-{{ $i }}" class="sr-only">{{ __('Barcode') }} {{ $i + 1 }}</label>
                                    <input id="xb-{{ $i }}" type="text" wire:model="extraBarcodes.{{ $i }}" class="form-input min-w-0 flex-1 font-mono" autocomplete="off" x-on:keydown.enter.prevent>
                                    <button type="button" wire:click="removeBarcodeRow({{ $i }})" class="grid size-12 shrink-0 place-items-center rounded-xl text-red-600 hover:bg-red-50 dark:hover:bg-red-950/40" aria-label="{{ __('Remove') }}"><x-icon name="x" /></button>
                                </div>
                                @error("extraBarcodes.$i") <p class="form-error">{{ $message }}</p> @enderror
                            @endforeach
                        </div>
                    </div>

                    {{-- Packaging units --}}
                    <div>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h3 class="font-semibold">{{ __('Cartons, packs and other units') }}</h3>
                            <button type="button" wire:click="addPackagingRow" class="btn btn-secondary">+ {{ __('Add unit') }}</button>
                        </div>
                        <p class="mt-1 text-xs text-slate-500">{{ __('For example: 1 Carton = 24 Piece. Weight and volume units (g, ml, l) convert automatically.') }}</p>
                        <div class="mt-3 space-y-3">
                            @foreach ($packaging as $i => $row)
                                <div class="rounded-xl border border-slate-200 p-4 dark:border-slate-700" wire:key="pk-{{ $i }}">
                                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                        <x-ui.field :label="__('Unit').' *'" for="pk-unit-{{ $i }}" error="packaging.{{ $i }}.unit">
                                            <select id="pk-unit-{{ $i }}" wire:model="packaging.{{ $i }}.unit" class="form-input">
                                                <option value="">{{ __('Select…') }}</option>
                                                @foreach ($units->where('uuid', '!=', $baseUnit) as $u) <option value="{{ $u->uuid }}">{{ __($u->name) }}</option> @endforeach
                                            </select>
                                            @error("packaging.$i") <p class="form-error">{{ $message }}</p> @enderror
                                        </x-ui.field>
                                        <x-ui.field :label="__('How many :base in one?', ['base' => $baseUnitModel ? __($baseUnitModel->name) : __('base units')])" for="pk-factor-{{ $i }}" error="packaging.{{ $i }}.factor">
                                            <input id="pk-factor-{{ $i }}" type="text" inputmode="decimal" wire:model="packaging.{{ $i }}.factor" class="form-input" placeholder="24">
                                        </x-ui.field>
                                        <x-ui.field :label="__('Barcode (optional)')" for="pk-barcode-{{ $i }}" error="packaging.{{ $i }}.barcode">
                                            <input id="pk-barcode-{{ $i }}" type="text" wire:model="packaging.{{ $i }}.barcode" class="form-input font-mono" autocomplete="off" x-on:keydown.enter.prevent>
                                        </x-ui.field>
                                        <x-ui.field :label="__('Selling price (optional)')" for="pk-price-{{ $i }}" error="packaging.{{ $i }}.price" :hint="__('Empty = unit price × quantity.')">
                                            <input id="pk-price-{{ $i }}" type="text" inputmode="decimal" wire:model="packaging.{{ $i }}.price" class="form-input" @disabled(! $can['prices'])>
                                        </x-ui.field>
                                    </div>
                                    <div class="mt-3 flex flex-wrap items-center gap-x-6 gap-y-2">
                                        <label class="flex min-h-10 items-center gap-2 text-sm"><input type="checkbox" wire:model="packaging.{{ $i }}.purchase" class="size-5 rounded border-slate-300 text-brand-600"> {{ __('Can be purchased') }}</label>
                                        <label class="flex min-h-10 items-center gap-2 text-sm"><input type="checkbox" wire:model="packaging.{{ $i }}.sell" class="size-5 rounded border-slate-300 text-brand-600"> {{ __('Can be sold') }}</label>
                                        <button type="button" wire:click="removePackagingRow({{ $i }})" class="ml-auto inline-flex min-h-10 items-center rounded-lg px-3 text-sm font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/40">{{ __('Remove') }}</button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="grid gap-5 md:grid-cols-2">
                    <x-ui.field :label="__('Minimum stock level')" for="min" error="minStockLevel"><input id="min" type="text" inputmode="decimal" wire:model="minStockLevel" class="form-input"></x-ui.field>
                    <x-ui.field :label="__('Reorder level')" for="reorder" error="reorderLevel"><input id="reorder" type="text" inputmode="decimal" wire:model="reorderLevel" class="form-input"></x-ui.field>
                    <label class="flex min-h-11 items-center gap-3 text-sm"><input type="checkbox" wire:model="trackBatches" class="size-5 rounded border-slate-300 text-brand-600"> {{ __('Track batches / lots') }}</label>
                    <label class="flex min-h-11 items-center gap-3 text-sm"><input type="checkbox" wire:model="trackExpiry" class="size-5 rounded border-slate-300 text-brand-600"> {{ __('Track expiry dates') }}</label>
                    @error('trackBatches') <p class="form-error md:col-span-2">{{ $message }}</p> @enderror
                    <x-ui.field class="md:col-span-2" :label="__('Description')" for="description" error="description"><textarea id="description" wire:model="description" rows="3" class="form-input"></textarea></x-ui.field>
                    <x-ui.field :label="__('Product image')" for="image" error="image" :hint="__('JPG, PNG or WebP, max 2 MB.')">
                        <input id="image" type="file" wire:model="image" accept="image/jpeg,image/png,image/webp" class="form-input file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2">
                        <p wire:loading wire:target="image" class="mt-1 text-xs text-slate-500">{{ __('Uploading…') }}</p>
                    </x-ui.field>
                </div>
            </div>
        </section>

        {{-- Actions: one compact sticky row on phones so Save is always reachable --}}
        <div class="sticky bottom-0 z-10 -mx-3 flex items-center gap-2 border-t border-slate-200 bg-white/95 px-3 py-2.5 pb-[max(0.625rem,env(safe-area-inset-bottom))] backdrop-blur sm:static sm:mx-0 sm:border-0 sm:bg-transparent sm:p-0 dark:border-slate-700 dark:bg-slate-900/95 sm:dark:bg-transparent" data-form-actions>
            @unless ($editing)
                <button type="button" wire:click="save('another')" class="btn btn-secondary min-w-0 flex-1 px-3 sm:flex-none sm:px-5" wire:loading.attr="disabled" wire:target="save" data-save-another>{{ __('Save & add another') }}</button>
            @endunless
            <button type="submit" class="btn btn-primary min-w-0 flex-1 px-3 sm:order-first sm:flex-none sm:px-6" wire:loading.attr="disabled" wire:target="save" data-save>
                <span wire:loading.remove wire:target="save">{{ __('Save product') }}</span><span wire:loading wire:target="save">{{ __('Saving…') }}</span>
            </button>
            <a href="{{ $editing ? route('catalogue.products.show', $product) : route('catalogue.products.index') }}" class="btn btn-secondary hidden sm:ml-auto sm:inline-flex">{{ __('Cancel') }}</a>
        </div>
        <a href="{{ $editing ? route('catalogue.products.show', $product) : route('catalogue.products.index') }}" class="btn btn-secondary w-full sm:hidden">{{ __('Cancel') }}</a>
    </form>
</div>
