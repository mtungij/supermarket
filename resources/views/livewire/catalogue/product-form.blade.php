<div>
    <x-ui.page-header :title="$editing ? __('Edit product') : __('New product')" :description="__('Fields marked * are required.')" />
    @include('components.ui.flash')

    <form wire:submit="save" class="space-y-6">
        <section class="card grid gap-5 md:grid-cols-2">
            <x-ui.field class="md:col-span-2" :label="__('Product name').' *'" for="name" error="name"><input id="name" type="text" wire:model="name" class="form-input" required></x-ui.field>
            <x-ui.field :label="__('SKU')" for="sku" error="sku" :hint="__('Leave empty to generate one.')"><input id="sku" type="text" wire:model="sku" class="form-input uppercase"></x-ui.field>
            <x-ui.field :label="__('Category').' *'" for="category" error="category_id">
                <select id="category" wire:model="category" class="form-input" required>
                    <option value="">{{ __('Select…') }}</option>
                    @foreach ($categories as $c) <option value="{{ $c->uuid }}">{{ $c->name }}</option> @endforeach
                </select>
                @error('category') <p class="form-error">{{ $message }}</p> @enderror
            </x-ui.field>
            <x-ui.field :label="__('Brand')" for="brand" error="brand_id">
                <select id="brand" wire:model="brand" class="form-input">
                    <option value="">{{ __('No brand') }}</option>
                    @foreach ($brands as $b) <option value="{{ $b->uuid }}">{{ $b->name }}</option> @endforeach
                </select>
            </x-ui.field>
            <x-ui.field :label="__('Product type').' *'" for="type" error="productType">
                <select id="type" wire:model="productType" class="form-input">@foreach ($types as $t) <option value="{{ $t->value }}">{{ __($t->label()) }}</option> @endforeach</select>
            </x-ui.field>
            <x-ui.field :label="__('Base (inventory) unit').' *'" for="baseUnit" error="base_unit_id" :hint="__('Stock is always counted in this unit.')">
                <select id="baseUnit" wire:model="baseUnit" class="form-input">@foreach ($units as $u) <option value="{{ $u->uuid }}">{{ __($u->name) }} ({{ __($u->dimension->label()) }})</option> @endforeach</select>
            </x-ui.field>
            <x-ui.field :label="__('Tax classification').' *'" for="tax" error="taxClass">
                <select id="tax" wire:model="taxClass" class="form-input">@foreach ($taxClasses as $t) <option value="{{ $t->value }}">{{ __($t->label()) }}</option> @endforeach</select>
            </x-ui.field>
            <x-ui.field class="md:col-span-2" :label="__('Description')" for="description" error="description"><textarea id="description" wire:model="description" rows="3" class="form-input"></textarea></x-ui.field>
        </section>

        <section class="card grid gap-5 md:grid-cols-4">
            <x-ui.field :label="__('Purchase cost (per base unit)')" for="cost" error="purchase_cost"><input id="cost" type="text" inputmode="decimal" wire:model="purchaseCost" class="form-input">@error('purchaseCost') <p class="form-error">{{ $message }}</p> @enderror</x-ui.field>
            <x-ui.field :label="__('Selling price (per base unit)')" for="price" error="selling_price"><input id="price" type="text" inputmode="decimal" wire:model="sellingPrice" class="form-input">@error('sellingPrice') <p class="form-error">{{ $message }}</p> @enderror</x-ui.field>
            <x-ui.field :label="__('Minimum stock level')" for="min" error="min_stock_level"><input id="min" type="text" inputmode="decimal" wire:model="minStockLevel" class="form-input"></x-ui.field>
            <x-ui.field :label="__('Reorder level')" for="reorder" error="reorder_level"><input id="reorder" type="text" inputmode="decimal" wire:model="reorderLevel" class="form-input"></x-ui.field>
            <label class="flex min-h-11 items-center gap-3 text-sm md:col-span-2"><input type="checkbox" wire:model="trackBatches" class="size-5 rounded border-slate-300 text-brand-600"> {{ __('Track batches / lots') }}</label>
            <label class="flex min-h-11 items-center gap-3 text-sm md:col-span-2"><input type="checkbox" wire:model="trackExpiry" class="size-5 rounded border-slate-300 text-brand-600"> {{ __('Track expiry dates') }}</label>
            @error('track_batches') <p class="form-error md:col-span-4">{{ $message }}</p> @enderror
        </section>

        <section class="card grid gap-5 md:grid-cols-3">
            @unless ($editing)
                <x-ui.field :label="__('Primary barcode')" for="barcode" error="barcode" :hint="__('Scan the product barcode or leave empty.')"><input id="barcode" type="text" wire:model="barcode" class="form-input font-mono" autocomplete="off"></x-ui.field>
                <x-ui.field :label="__('Barcode type')" for="barcodeType" error="barcodeType">
                    <select id="barcodeType" wire:model="barcodeType" class="form-input">@foreach ($barcodeTypes as $t) <option value="{{ $t->value }}">{{ __($t->label()) }}</option> @endforeach</select>
                </x-ui.field>
            @endunless
            <x-ui.field :label="__('Product image')" for="image" error="image" :hint="__('JPG, PNG or WebP, max 2 MB.')">
                <input id="image" type="file" wire:model="image" accept="image/jpeg,image/png,image/webp" class="form-input file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2">
                <p wire:loading wire:target="image" class="mt-1 text-xs text-slate-500">{{ __('Uploading…') }}</p>
            </x-ui.field>
        </section>

        <div class="flex gap-3">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">{{ __('Save product') }}</span><span wire:loading wire:target="save">{{ __('Saving…') }}</span>
            </button>
            <a href="{{ route('catalogue.products.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
        </div>
    </form>
</div>
