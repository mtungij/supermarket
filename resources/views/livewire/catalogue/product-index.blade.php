<div>
    <x-ui.page-header :title="__('Products')" :description="__('One catalogue for all branches. Prices and stock can differ per branch.')">
        @if ($canCreate)
            <x-slot:actions><a href="{{ route('catalogue.products.create') }}" class="btn btn-primary"><x-icon name="plus" /> {{ __('Add product') }}</a></x-slot:actions>
        @endif
    </x-ui.page-header>
    @include('components.ui.flash')

    <div class="mb-4 grid gap-3 md:grid-cols-4">
        <div class="md:col-span-2"><x-ui.search wire:model.live.debounce.300ms="search" :placeholder="__('Name, SKU or barcode')" /></div>
        <select wire:model.live="category" class="form-input" aria-label="{{ __('Category') }}">
            <option value="">{{ __('All categories') }}</option>
            @foreach ($categories as $c) <option value="{{ $c->uuid }}">{{ $c->name }}</option> @endforeach
        </select>
        <div class="grid grid-cols-2 gap-3">
            <select wire:model.live="brand" class="form-input" aria-label="{{ __('Brand') }}">
                <option value="">{{ __('All brands') }}</option>
                @foreach ($brands as $b) <option value="{{ $b->uuid }}">{{ $b->name }}</option> @endforeach
            </select>
            <select wire:model.live="status" class="form-input" aria-label="{{ __('Status') }}">
                <option value="active">{{ __('Active') }}</option><option value="inactive">{{ __('Inactive') }}</option><option value="all">{{ __('All') }}</option>
            </select>
        </div>
    </div>

    <x-ui.table>
        <x-slot:head>
            <th class="px-4 py-3">{{ __('Product') }}</th><th class="px-4 py-3">{{ __('Category') }}</th><th class="px-4 py-3">{{ __('Barcode') }}</th>
            <th class="px-4 py-3">{{ __('Base unit') }}</th><th class="px-4 py-3 text-right">{{ __('Selling price') }}</th><th class="px-4 py-3">{{ __('Status') }}</th>
        </x-slot:head>
        @forelse ($products as $product)
            <tr wire:key="p-{{ $product->uuid }}" class="hover:bg-slate-50 dark:hover:bg-slate-800/60">
                <td class="px-4 py-3">
                    <a href="{{ route('catalogue.products.show', $product) }}" class="font-medium text-brand-700 hover:underline dark:text-brand-100">{{ $product->name }}</a>
                    <p class="text-xs text-slate-500">{{ $product->sku }}{{ $product->brand ? ' · '.$product->brand->name : '' }}</p>
                </td>
                <td class="px-4 py-3">{{ $product->category->name }}</td>
                <td class="px-4 py-3 font-mono text-xs">{{ $product->barcodes->first()?->barcode ?? '—' }}</td>
                <td class="px-4 py-3">{{ __($product->baseUnit->name) }}</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ \App\Support\Decimal::format($product->selling_price) }}</td>
                <td class="px-4 py-3"><x-ui.status :status="$product->is_active ? 'active' : 'inactive'" /></td>
            </tr>
        @empty
            <x-ui.empty-row colspan="6">{{ __('No products found.') }}</x-ui.empty-row>
        @endforelse
    </x-ui.table>
    <div class="mt-4">{{ $products->links() }}</div>
</div>
