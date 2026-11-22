@use('App\Support\Decimal')
<div class="space-y-6">
    <x-ui.page-header :title="$product->name" :description="$product->sku.' · '.$product->category->name.($product->brand ? ' · '.$product->brand->name : '')">
        <x-slot:actions>
            @if ($can['update']) <a href="{{ route('catalogue.products.edit', $product) }}" class="btn btn-secondary">{{ __('Edit') }}</a> @endif
            @if ($barcodes->isNotEmpty()) <a href="{{ route('catalogue.products.labels', $product) }}" target="_blank" class="btn btn-secondary">{{ __('Print labels') }}</a> @endif
            @if ($can['deactivate'])
                <button type="button" wire:click="toggleActive" wire:confirm="{{ $product->is_active ? __('Deactivate this product?') : __('Activate this product?') }}" class="btn {{ $product->is_active ? 'btn-danger' : 'btn-primary' }}">{{ $product->is_active ? __('Deactivate') : __('Activate') }}</button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
    @include('components.ui.flash')

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="card flex gap-4 lg:col-span-2">
            @if ($product->image_path)
                <img src="{{ route('catalogue.products.image', $product) }}" alt="{{ $product->name }}" class="size-28 shrink-0 rounded-xl object-cover">
            @endif
            <dl class="grid flex-1 grid-cols-2 gap-3 text-sm md:grid-cols-3">
                <div><dt class="text-slate-500">{{ __('Type') }}</dt><dd>{{ __($product->product_type->label()) }}</dd></div>
                <div><dt class="text-slate-500">{{ __('Base unit') }}</dt><dd>{{ __($product->baseUnit->name) }}</dd></div>
                <div><dt class="text-slate-500">{{ __('Tax') }}</dt><dd>{{ __($product->tax_class->label()) }}</dd></div>
                <div><dt class="text-slate-500">{{ __('Reorder level') }}</dt><dd>{{ Decimal::trim($product->reorder_level) }}</dd></div>
                <div><dt class="text-slate-500">{{ __('Minimum stock') }}</dt><dd>{{ Decimal::trim($product->min_stock_level) }}</dd></div>
                <div><dt class="text-slate-500">{{ __('Tracking') }}</dt><dd>{{ collect([$product->track_batches ? __('Batches') : null, $product->track_expiry ? __('Expiry') : null])->filter()->join(', ') ?: '—' }}</dd></div>
                <div><dt class="text-slate-500">{{ __('Status') }}</dt><dd><x-ui.status :status="$product->is_active ? 'active' : 'inactive'" /></dd></div>
            </dl>
        </div>
        <div class="card text-sm">
            <p class="mb-3 font-semibold">{{ __('Cost & margin') }} @if ($branch)<span class="font-normal text-slate-500">· {{ $branch->name }}</span>@endif</p>
            <dl class="space-y-2">
                <div class="flex justify-between"><dt class="text-slate-500">{{ __('Latest purchase cost') }}</dt><dd class="tabular-nums">{{ Decimal::isPositive((string) $product->purchase_cost) ? Decimal::format($product->purchase_cost, 2) : '—' }}</dd></div>
                @if ($product->reference_buying_price !== null)
                    <div class="flex justify-between"><dt class="text-slate-500">{{ __('Reference buying price') }}</dt><dd class="tabular-nums">{{ Decimal::format($product->reference_buying_price, 2) }}</dd></div>
                @endif
                <div class="flex justify-between"><dt class="text-slate-500">{{ __('Weighted-average cost') }}</dt><dd class="tabular-nums">{{ Decimal::isPositive($averageCost) ? Decimal::format($averageCost, 2) : '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">{{ __('Selling price') }}</dt><dd class="tabular-nums">{{ Decimal::format($basePrice) }}</dd></div>
                <div class="flex justify-between border-t border-slate-200 pt-2 dark:border-slate-700"><dt class="text-slate-500">{{ __('Gross profit estimate') }}</dt><dd class="font-semibold tabular-nums">{{ $margin !== null ? Decimal::format($margin) : '—' }}@if ($marginPercent !== null) ({{ $marginPercent }}%)@endif</dd></div>
            </dl>
            <p class="mt-2 text-xs text-slate-400">{{ __('Per base unit, excluding tax. Uses weighted-average cost when stock exists.') }}</p>
            @if ($margin === null)<p class="mt-1 text-xs text-slate-400">{{ __('No actual cost yet: profit is shown after the first goods receipt. The reference buying price is never used as cost.') }}</p>@endif
        </div>
    </div>

    {{-- Units & conversions --}}
    <section class="card">
        <h3 class="mb-4 font-semibold">{{ __('Units & conversions') }}</h3>
        <x-ui.table class="shadow-none">
            <x-slot:head><th class="px-4 py-3">{{ __('Unit') }}</th><th class="px-4 py-3">{{ __('Conversion') }}</th><th class="px-4 py-3">{{ __('Purchase') }}</th><th class="px-4 py-3">{{ __('Sell') }}</th><th class="px-4 py-3 text-right">{{ __('Current price') }}</th><th class="px-4 py-3"></th></x-slot:head>
            @foreach ($units as $u)
                <tr wire:key="pu-{{ $u->id }}" @class(['opacity-50' => ! $u->is_active])>
                    <td class="px-4 py-3 font-medium">{{ __($u->unit->name) }} @if ($u->is_base) <x-ui.badge color="green">{{ __('Base') }}</x-ui.badge> @endif</td>
                    <td class="px-4 py-3">1 {{ __($u->unit->name) }} = {{ Decimal::trim($u->factor) }} {{ __($product->baseUnit->name) }}</td>
                    <td class="px-4 py-3">{{ $u->can_purchase ? __('Yes') : __('No') }}</td>
                    <td class="px-4 py-3">{{ $u->can_sell ? __('Yes') : __('No') }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ isset($quotes[$u->id]) ? Decimal::format($quotes[$u->id]->price) : '—' }}</td>
                    <td class="px-4 py-3 text-right">
                        @if ($can['update'] && ! $u->is_base && $u->is_active)
                            <button type="button" wire:click="removeUnit({{ $u->id }})" wire:confirm="{{ __('Remove this unit? Past documents keep their own conversion.') }}" class="inline-flex min-h-10 items-center rounded-lg px-2 text-sm font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/40">{{ __('Remove') }}</button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
        @if ($can['update'])
            <form wire:submit="saveUnit" class="mt-4 grid gap-4 md:grid-cols-5 md:items-end">
                <x-ui.field :label="__('Unit')" for="unitUuid" error="unitUuid">
                    <select id="unitUuid" wire:model="unitUuid" class="form-input"><option value="">{{ __('Select…') }}</option>@foreach ($availableUnits as $au) <option value="{{ $au->uuid }}">{{ __($au->name) }}</option> @endforeach</select>
                    @error('unit') <p class="form-error">{{ $message }}</p> @enderror
                </x-ui.field>
                <x-ui.field :label="__(':base per unit', ['base' => __($product->baseUnit->name)])" for="factor" error="factor" :hint="__('Leave empty for g/ml (automatic).')"><input id="factor" type="text" inputmode="decimal" wire:model="factor" class="form-input" placeholder="24"></x-ui.field>
                <label class="flex min-h-12 items-center gap-2 text-sm"><input type="checkbox" wire:model="canPurchase" class="size-5 rounded"> {{ __('Purchase') }}</label>
                <label class="flex min-h-12 items-center gap-2 text-sm"><input type="checkbox" wire:model="canSell" class="size-5 rounded"> {{ __('Sell') }}</label>
                <button type="submit" class="btn btn-primary">{{ __('Save unit') }}</button>
            </form>
        @endif
    </section>

    {{-- Barcodes --}}
    <section class="card">
        <h3 class="mb-4 font-semibold">{{ __('Barcodes') }}</h3>
        <ul class="divide-y divide-slate-100 dark:divide-slate-700">
            @forelse ($barcodes as $b)
                <li wire:key="bc-{{ $b->id }}" class="flex flex-wrap items-center gap-3 py-3">
                    <span class="font-mono">{{ $b->barcode }}</span>
                    <x-ui.badge>{{ __($b->type->label()) }}</x-ui.badge>
                    <span class="text-sm text-slate-500">{{ __($b->productUnit->unit->name) }}</span>
                    @if ($b->is_primary) <x-ui.badge color="green">{{ __('Primary') }}</x-ui.badge> @endif
                    @if ($can['update'])
                        <span class="ml-auto flex gap-2">
                            @unless ($b->is_primary) <button type="button" wire:click="makePrimary({{ $b->id }})" class="text-sm font-medium">{{ __('Make primary') }}</button> @endunless
                            <button type="button" wire:click="removeBarcode({{ $b->id }})" wire:confirm="{{ __('Remove this barcode?') }}" class="inline-flex min-h-10 items-center rounded-lg px-2 text-sm font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/40">{{ __('Remove') }}</button>
                        </span>
                    @endif
                </li>
            @empty
                <li class="py-3 text-sm text-slate-500">{{ __('No barcodes yet.') }}</li>
            @endforelse
        </ul>
        @if ($can['update'])
            <form wire:submit="addBarcode" class="mt-4 grid gap-4 sm:grid-cols-2 sm:items-end xl:grid-cols-5">
                <x-ui.field class="sm:col-span-2" :label="__('Barcode')" for="new-barcode" error="barcode"><input id="new-barcode" type="text" wire:model="barcode" class="form-input font-mono" autocomplete="off" placeholder="{{ __('Scan or type') }}"></x-ui.field>
                <x-ui.field :label="__('Type')" for="barcode-type" error="barcodeType"><select id="barcode-type" wire:model="barcodeType" class="form-input">@foreach ($barcodeTypes as $t) <option value="{{ $t->value }}">{{ __($t->label()) }}</option> @endforeach</select></x-ui.field>
                <x-ui.field :label="__('Unit')" for="barcode-unit" error="barcodeUnit"><select id="barcode-unit" wire:model="barcodeUnit" class="form-input"><option value="">{{ __($product->baseUnit->name) }}</option>@foreach ($units->where('is_base', false)->where('is_active', true) as $u) <option value="{{ $u->id }}">{{ __($u->unit->name) }} (×{{ Decimal::trim($u->factor) }})</option> @endforeach</select></x-ui.field>
                <div class="flex flex-wrap gap-2 sm:col-span-2 xl:col-span-1"><button type="submit" class="btn btn-primary">{{ __('Add') }}</button><button type="button" wire:click="generateBarcode" class="btn btn-secondary" title="{{ __('Generate internal barcode') }}">{{ __('Generate') }}</button></div>
            </form>
        @endif
    </section>

    {{-- Prices --}}
    <section class="card">
        <h3 class="mb-1 font-semibold">{{ __('Selling prices') }}</h3>
        <p class="mb-4 text-sm text-slate-500">{{ __('Prices are effective-dated and never overwritten. Branch prices override company-wide prices.') }}</p>
        @if ($can['prices'])
            <form wire:submit="savePrice" class="mb-5 grid gap-4 md:grid-cols-5 md:items-end">
                <x-ui.field :label="__('Unit')" for="price-unit" error="priceUnit"><select id="price-unit" wire:model="priceUnit" class="form-input"><option value="">{{ __('Select…') }}</option>@foreach ($units->where('is_active', true)->where('can_sell', true) as $u) <option value="{{ $u->id }}">{{ __($u->unit->name) }}</option> @endforeach</select>@error('unit') <p class="form-error">{{ $message }}</p> @enderror</x-ui.field>
                <x-ui.field :label="__('Branch')" for="price-branch" error="priceBranch"><select id="price-branch" wire:model="priceBranch" class="form-input"><option value="">{{ __('All branches') }}</option>@foreach ($branches as $br) <option value="{{ $br->uuid }}">{{ $br->name }}</option> @endforeach</select></x-ui.field>
                <x-ui.field :label="__('Price')" for="price-amount" error="price"><input id="price-amount" type="text" inputmode="decimal" wire:model="price" class="form-input"></x-ui.field>
                <x-ui.field :label="__('Effective from')" for="price-from" error="effective_from" :hint="__('Empty = now')"><input id="price-from" type="datetime-local" wire:model="effectiveFrom" class="form-input">@error('effectiveFrom') <p class="form-error">{{ $message }}</p> @enderror</x-ui.field>
                <button type="submit" class="btn btn-primary">{{ __('Set price') }}</button>
            </form>
        @endif
        <x-ui.table class="shadow-none">
            <x-slot:head><th class="px-4 py-3">{{ __('Effective from') }}</th><th class="px-4 py-3">{{ __('Unit') }}</th><th class="px-4 py-3">{{ __('Branch') }}</th><th class="px-4 py-3 text-right">{{ __('Price') }}</th><th class="px-4 py-3">{{ __('Set by') }}</th></x-slot:head>
            @forelse ($priceHistory as $ph)
                <tr wire:key="ph-{{ $ph->id }}">
                    <td class="px-4 py-3">{{ $ph->effective_from->timezone($product->company->timezone)->format('d/m/Y H:i') }} @if ($ph->effective_from->isFuture()) <x-ui.badge color="amber">{{ __('Scheduled') }}</x-ui.badge> @endif</td>
                    <td class="px-4 py-3">{{ __($ph->productUnit->unit->name) }}</td>
                    <td class="px-4 py-3">{{ $ph->branch?->name ?? __('All branches') }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ Decimal::format($ph->price) }}</td>
                    <td class="px-4 py-3 text-slate-500">{{ $ph->creator?->name ?? '—' }}</td>
                </tr>
            @empty
                <x-ui.empty-row colspan="5">{{ __('Using the default selling price.') }}</x-ui.empty-row>
            @endforelse
        </x-ui.table>
    </section>

    @if ($can['inventory'] && $branch)
        <section class="card">
            <h3 class="mb-4 font-semibold">{{ __('Stock in :branch', ['branch' => $branch->name]) }}</h3>
            <x-ui.table class="shadow-none">
                <x-slot:head><th class="px-4 py-3">{{ __('Location') }}</th><th class="px-4 py-3">{{ __('Batch') }}</th><th class="px-4 py-3">{{ __('Expiry') }}</th><th class="px-4 py-3 text-right">{{ __('Quantity') }}</th></x-slot:head>
                @forelse ($balances as $bal)
                    <tr><td class="px-4 py-3">{{ $bal->location->name }}</td><td class="px-4 py-3">{{ $bal->batch?->batch_number ?? '—' }}</td><td class="px-4 py-3">{{ $bal->batch?->expires_on?->format('d/m/Y') ?? '—' }}</td><td class="px-4 py-3 text-right tabular-nums">{{ Decimal::trim($bal->quantity) }} {{ __($product->baseUnit->name) }}</td></tr>
                @empty
                    <x-ui.empty-row colspan="4">{{ __('No stock in this branch.') }}</x-ui.empty-row>
                @endforelse
            </x-ui.table>
        </section>
    @endif
</div>
