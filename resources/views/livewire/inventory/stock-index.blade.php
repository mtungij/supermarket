@use('App\Support\Decimal')
<div class="space-y-6">
    <x-ui.page-header :title="__('Stock on hand')" :description="__(':branch · balances are read-only and change only through receipts, adjustments and transfers.', ['branch' => $branch->name])" />

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card :label="__('Stock value (weighted average)')" :value="Decimal::format($valuation['on_hand_value'])" icon="wallet" />
        <x-ui.stat-card :label="__('Products in stock')" :value="$valuation['products_in_stock']" icon="box" />
        <x-ui.stat-card :label="__('Low stock')" :value="$lowStock->count()" :hint="__(':n critical', ['n' => $lowStock->where('critical', true)->count()])" icon="chart" />
        <x-ui.stat-card :label="__('Expiring within :days days', ['days' => $expiryDays])" :value="$expiring->count()" :hint="__('In transit out: :value', ['value' => Decimal::format($valuation['in_transit_out_value'])])" icon="shield" />
    </div>

    <div class="flex gap-2 border-b border-slate-200 dark:border-slate-700" role="tablist">
        @foreach (['stock' => __('All stock'), 'low' => __('Low stock alerts'), 'expiry' => __('Expiry alerts')] as $key => $label)
            <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" @class(['-mb-px border-b-2 px-4 py-3 text-sm font-medium', 'border-brand-600 text-brand-700 dark:text-brand-100' => $tab === $key, 'border-transparent text-slate-500' => $tab !== $key])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($tab === 'stock')
        <div class="grid gap-3 md:grid-cols-4">
            <div class="md:col-span-2"><x-ui.search wire:model.live.debounce.300ms="search" :placeholder="__('Name or SKU')" /></div>
            <select wire:model.live="category" class="form-input" aria-label="{{ __('Category') }}"><option value="">{{ __('All categories') }}</option>@foreach ($categories as $c) <option value="{{ $c->uuid }}">{{ $c->name }}</option> @endforeach</select>
            <select wire:model.live="stock" class="form-input" aria-label="{{ __('Stock') }}"><option value="">{{ __('All products') }}</option><option value="in">{{ __('In stock') }}</option><option value="low">{{ __('At or below reorder level') }}</option><option value="out">{{ __('Out of stock') }}</option></select>
        </div>
        <x-ui.table>
            <x-slot:head><th class="px-4 py-3">{{ __('Product') }}</th><th class="px-4 py-3">{{ __('Category') }}</th><th class="px-4 py-3 text-right">{{ __('On hand') }}</th><th class="px-4 py-3 text-right">{{ __('Reorder level') }}</th><th class="px-4 py-3 text-right">{{ __('Avg. cost') }}</th><th class="px-4 py-3 text-right">{{ __('Value') }}</th></x-slot:head>
            @forelse ($products as $p)
                <tr wire:key="st-{{ $p->id }}">
                    <td class="px-4 py-3"><a href="{{ route('catalogue.products.show', $p->uuid) }}" class="font-medium text-brand-700 hover:underline dark:text-brand-100">{{ $p->name }}</a><p class="text-xs text-slate-500">{{ $p->sku }}</p></td>
                    <td class="px-4 py-3">{{ $p->category->name }}</td>
                    <td @class(['px-4 py-3 text-right tabular-nums', 'font-semibold text-red-600' => Decimal::cmp((string) $p->on_hand, (string) $p->reorder_level) <= 0 && Decimal::isPositive((string) $p->reorder_level)])>{{ Decimal::trim((string) $p->on_hand) }} {{ __($p->baseUnit->name) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ Decimal::trim($p->reorder_level) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ Decimal::format((string) $p->average_cost) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ Decimal::format(Decimal::mul((string) $p->on_hand, (string) $p->average_cost)) }}</td>
                </tr>
            @empty
                <x-ui.empty-row colspan="6">{{ __('No products match.') }}</x-ui.empty-row>
            @endforelse
        </x-ui.table>
        <div>{{ $products->links() }}</div>
    @elseif ($tab === 'low')
        <x-ui.table>
            <x-slot:head><th class="px-4 py-3">{{ __('Product') }}</th><th class="px-4 py-3 text-right">{{ __('On hand') }}</th><th class="px-4 py-3 text-right">{{ __('Minimum') }}</th><th class="px-4 py-3 text-right">{{ __('Reorder level') }}</th><th class="px-4 py-3">{{ __('Alert') }}</th></x-slot:head>
            @forelse ($lowStock as $p)
                <tr><td class="px-4 py-3 font-medium">{{ $p->name }}</td><td class="px-4 py-3 text-right tabular-nums">{{ Decimal::trim((string) $p->on_hand) }} {{ __($p->baseUnit->name) }}</td><td class="px-4 py-3 text-right">{{ Decimal::trim($p->min_stock_level) }}</td><td class="px-4 py-3 text-right">{{ Decimal::trim($p->reorder_level) }}</td><td class="px-4 py-3">@if ($p->critical)<x-ui.badge color="red">{{ __('Below minimum') }}</x-ui.badge>@else<x-ui.badge color="amber">{{ __('Reorder') }}</x-ui.badge>@endif</td></tr>
            @empty
                <x-ui.empty-row colspan="5">{{ __('No low-stock alerts.') }}</x-ui.empty-row>
            @endforelse
        </x-ui.table>
    @else
        <x-ui.table>
            <x-slot:head><th class="px-4 py-3">{{ __('Product') }}</th><th class="px-4 py-3">{{ __('Batch') }}</th><th class="px-4 py-3">{{ __('Location') }}</th><th class="px-4 py-3">{{ __('Expiry') }}</th><th class="px-4 py-3 text-right">{{ __('Quantity') }}</th></x-slot:head>
            @forelse ($expiring as $e)
                @php($expires = \Illuminate\Support\Carbon::parse($e->expires_on))
                <tr><td class="px-4 py-3 font-medium">{{ $e->product->name }}</td><td class="px-4 py-3 font-mono text-xs">{{ $e->batch_number }}</td><td class="px-4 py-3">{{ $e->location->name }}</td>
                    <td class="px-4 py-3">{{ $expires->format('d/m/Y') }} @if ($expires->lt(today()))<x-ui.badge color="red">{{ __('Expired') }}</x-ui.badge>@else<x-ui.badge color="amber">{{ trans_choice(':count day|:count days', (int) today()->diffInDays($expires)) }}</x-ui.badge>@endif</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ Decimal::trim((string) $e->quantity) }} {{ __($e->product->baseUnit->name) }}</td></tr>
            @empty
                <x-ui.empty-row colspan="5">{{ __('No expiry alerts.') }}</x-ui.empty-row>
            @endforelse
        </x-ui.table>
    @endif
</div>
