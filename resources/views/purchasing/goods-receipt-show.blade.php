@use('App\Support\Decimal')
<x-layouts.tenant :title="$receipt->reference">
    <x-ui.page-header :title="$receipt->reference" :description="$receipt->supplier->name.' · '.$receipt->branch->name.' · '.$receipt->location->name">
        <x-slot:actions><x-ui.status :status="$receipt->status" /><a href="{{ route('purchasing.orders.show', $receipt->purchaseOrder) }}" class="btn btn-secondary">{{ $receipt->purchaseOrder->reference }}</a></x-slot:actions>
    </x-ui.page-header>
    <div class="mb-6 grid gap-4 md:grid-cols-4">
        <x-ui.stat-card :label="__('Received date')" :value="$receipt->received_date->format('d/m/Y')" icon="truck" />
        <x-ui.stat-card :label="__('Supplier invoice no.')" :value="$receipt->supplier_invoice_number ?? $receipt->supplier_reference ?? '—'" :hint="$receipt->delivery_note_number ? __('Delivery note: :n', ['n' => $receipt->delivery_note_number]) : null" icon="receipt" />
        <x-ui.stat-card :label="__('Posted by')" :value="$receipt->poster?->name ?? '—'" :hint="$receipt->posted_at?->timezone($receipt->branch->company->timezone)->format('d/m/Y H:i')" icon="users" />
        <x-ui.stat-card :label="__('Owed to supplier (incl. tax)')" :value="Decimal::format($receipt->total)" icon="wallet" />
    </div>
    @if (Decimal::isPositive((string) $receipt->landed_cost_total))
        <div class="mb-6 grid gap-4 md:grid-cols-3">
            <x-ui.stat-card :label="__('Goods purchase value')" :value="Decimal::format($receipt->goods_value)" :hint="__('excl. tax')" icon="receipt" />
            <x-ui.stat-card :label="__('Additional costs')" :value="Decimal::format($receipt->landed_cost_total)" :hint="$receipt->costs->map(fn ($c) => __($c->label()).' '.Decimal::format($c->amount))->join(' · ')" icon="truck" />
            <x-ui.stat-card :label="__('Total landed value')" :value="Decimal::format(Decimal::add((string) $receipt->goods_value, (string) $receipt->landed_cost_total))" icon="wallet" />
        </div>
    @endif
    <x-ui.table>
        <x-slot:head><th class="px-4 py-3">{{ __('Product') }}</th><th class="px-4 py-3">{{ __('Quantity') }}</th><th class="px-4 py-3">{{ __('Batch') }}</th><th class="px-4 py-3">{{ __('Expiry') }}</th><th class="px-4 py-3 text-right">{{ __('Unit cost') }}</th><th class="px-4 py-3">{{ __('Location') }}</th><th class="px-4 py-3 text-right">{{ __('Cost per base unit') }}</th><th class="px-4 py-3 text-right">{{ __('Landed cost per base unit') }}</th><th class="px-4 py-3 text-right">{{ __('Line total') }}</th></x-slot:head>
        @foreach ($receipt->lines as $l)
            <tr>
                <td class="px-4 py-3 font-medium">{{ $l->product->name }}</td>
                <td class="px-4 py-3">{{ Decimal::trim($l->quantity) }} {{ __($l->productUnit->unit->name) }}<p class="text-xs text-slate-500">= {{ Decimal::trim($l->quantity_base) }} {{ __($l->product->baseUnit->name) }} ({{ __('factor') }} {{ Decimal::trim($l->conversion_factor) }})</p></td>
                <td class="px-4 py-3">{{ $l->batch?->batch_number ?? $l->batch_number ?? '—' }}</td>
                <td class="px-4 py-3">{{ $l->expires_on?->format('d/m/Y') ?? '—' }}</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ Decimal::format($l->unit_cost) }}</td>
                <td class="px-4 py-3">{{ __(($l->location ?? $receipt->location)->name) }}@if ($l->notes)<p class="text-xs text-slate-500">{{ $l->notes }}</p>@endif</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ Decimal::format($l->unit_cost_base, 4) }}</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ Decimal::format($l->inventoryUnitCostBase(), 4) }}</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ Decimal::format($l->line_total) }}</td>
            </tr>
        @endforeach
    </x-ui.table>
    @if ($receipt->notes)<p class="card mt-4 text-sm">{{ $receipt->notes }}</p>@endif
</x-layouts.tenant>
