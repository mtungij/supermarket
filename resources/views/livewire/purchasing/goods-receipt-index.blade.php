<div>
    <x-ui.page-header :title="__('Goods received')" :description="__('Posted receipts are the only way purchased stock enters inventory.')" />
    <div class="mb-4"><x-ui.search wire:model.live.debounce.300ms="search" :placeholder="__('GRN or supplier invoice number')" /></div>
    <x-ui.table>
        <x-slot:head><th class="px-4 py-3">{{ __('Reference') }}</th><th class="px-4 py-3">{{ __('Purchase order') }}</th><th class="px-4 py-3">{{ __('Supplier') }}</th><th class="px-4 py-3">{{ __('Branch / location') }}</th><th class="px-4 py-3">{{ __('Received') }}</th><th class="px-4 py-3 text-right">{{ __('Total') }}</th><th class="px-4 py-3">{{ __('Status') }}</th></x-slot:head>
        @forelse ($receipts as $r)
            <tr wire:key="grn-{{ $r->uuid }}">
                <td class="px-4 py-3"><a href="{{ route('purchasing.receipts.show', $r) }}" class="font-medium text-brand-700 hover:underline dark:text-brand-100">{{ $r->reference }}</a><p class="text-xs text-slate-500">{{ $r->supplier_reference }}</p></td>
                <td class="px-4 py-3"><a href="{{ route('purchasing.orders.show', $r->purchaseOrder->uuid) }}" class="hover:underline">{{ $r->purchaseOrder->reference }}</a></td>
                <td class="px-4 py-3">{{ $r->supplier->name }}</td>
                <td class="px-4 py-3">{{ $r->branch->name }} · {{ $r->location->name }}</td>
                <td class="px-4 py-3">{{ $r->received_date->format('d/m/Y') }}</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ \App\Support\Decimal::format($r->total) }}</td>
                <td class="px-4 py-3"><x-ui.status :status="$r->status" /></td>
            </tr>
        @empty
            <x-ui.empty-row colspan="7">{{ __('No goods received yet.') }}</x-ui.empty-row>
        @endforelse
    </x-ui.table>
    <div class="mt-4">{{ $receipts->links() }}</div>
</div>
