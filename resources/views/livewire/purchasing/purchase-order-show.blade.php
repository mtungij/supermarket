@use('App\Support\Decimal')
<div class="space-y-6">
    <x-ui.page-header :title="$order->reference" :description="$order->supplier->name.' · '.$order->branch->name">
        <x-slot:actions>
            <x-ui.status :status="$order->status" />
            @if ($can['edit']) <a href="{{ route('purchasing.orders.edit', $order) }}" class="btn btn-secondary">{{ __('Edit') }}</a> @endif
            <a href="{{ route('purchasing.orders.print', $order) }}" target="_blank" class="btn btn-secondary">{{ __('Print PO') }}</a>
            @if ($can['submit']) <button type="button" wire:click="act('send')" wire:loading.attr="disabled" class="btn btn-primary">{{ __('Send PO') }}</button> @endif
            @if ($can['approve']) <button type="button" wire:click="act('approve')" wire:loading.attr="disabled" wire:confirm="{{ __('Approve this purchase order?') }}" class="btn btn-primary">{{ __('Approve') }}</button> @endif
            @if ($can['receive']) <a href="{{ route('purchasing.orders.receive', $order) }}" class="btn btn-primary" @if ($can['quick']) title="{{ __('Quick receive: the order is approved as part of receiving') }}" @endif>{{ $can['quick'] ? __('Quick receive') : __('Receive purchase') }}</a> @endif
        </x-slot:actions>
    </x-ui.page-header>
    @include('components.ui.flash')
    @error('status') <div class="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200" role="alert">{{ $message }}</div> @enderror

    <div class="grid gap-4 md:grid-cols-4">
        <x-ui.stat-card :label="__('Order total')" :value="$order->currency.' '.Decimal::format($order->total)" icon="receipt" />
        <x-ui.stat-card :label="__('Order date')" :value="$order->order_date->format('d/m/Y')" :hint="$order->expected_date ? __('Expected :date', ['date' => $order->expected_date->format('d/m/Y')]) : null" icon="truck" />
        <x-ui.stat-card :label="__('Created by')" :value="$order->creator?->name ?? '—'" icon="users" />
        <x-ui.stat-card :label="__('Approved by')" :value="$order->approver?->name ?? '—'" :hint="$order->approved_at?->timezone($order->branch->company->timezone)->format('d/m/Y H:i')" icon="shield" />
    </div>

    <x-ui.table>
        <x-slot:head><th class="px-4 py-3">{{ __('Product') }}</th><th class="px-4 py-3">{{ __('Ordered') }}</th><th class="px-4 py-3">{{ __('Received') }}</th><th class="px-4 py-3 text-right">{{ __('Unit cost') }}</th><th class="px-4 py-3 text-right">{{ __('Discount') }}</th><th class="px-4 py-3 text-right">{{ __('Tax') }}</th><th class="px-4 py-3 text-right">{{ __('Line total') }}</th></x-slot:head>
        @foreach ($order->lines as $line)
            <tr>
                <td class="px-4 py-3 font-medium">{{ $line->product->name }}</td>
                <td class="px-4 py-3">{{ Decimal::trim($line->quantity) }} {{ __($line->productUnit->unit->name) }}<p class="text-xs text-slate-500">= {{ Decimal::trim($line->quantity_base) }} {{ __($line->product->baseUnit->name) }}</p></td>
                <td class="px-4 py-3">{{ Decimal::trim($line->received_quantity_base) }} {{ __($line->product->baseUnit->name) }}</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ Decimal::format($line->unit_cost) }}</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ Decimal::format($line->discount_amount) }}</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ Decimal::format($line->tax_amount) }} <span class="text-xs text-slate-500">({{ Decimal::trim($line->tax_rate) }}%)</span></td>
                <td class="px-4 py-3 text-right tabular-nums">{{ Decimal::format($line->line_total) }}</td>
            </tr>
        @endforeach
        <tr class="bg-slate-50 font-medium dark:bg-slate-800"><td colspan="4" class="px-4 py-3 text-right">{{ __('Subtotal') }} {{ Decimal::format($order->subtotal) }} · {{ __('Discount') }} {{ Decimal::format($order->discount_total) }} · {{ __('Tax') }} {{ Decimal::format($order->tax_total) }}</td><td colspan="3" class="px-4 py-3 text-right">{{ __('Total') }} {{ $order->currency }} {{ Decimal::format($order->total) }}</td></tr>
    </x-ui.table>

    @if ($proposals->isNotEmpty())
        <section class="card" data-proposed-prices>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="font-semibold">{{ __('Proposed selling prices') }}</h3>
                    <p class="text-sm text-slate-500">{{ __('Recorded with this purchase. Catalogue prices change only when you apply them.') }}</p>
                </div>
                @if ($can['prices'] && $pendingProposals > 0)
                    <button type="button" wire:click="$set('confirmingPrices', true)" class="btn btn-primary" data-apply-prices aria-haspopup="dialog">{{ __('Update selling prices') }} ({{ $pendingProposals }})</button>
                @endif
            </div>
            <ul class="mt-3 divide-y divide-slate-100 text-sm dark:divide-slate-700">
                @foreach ($proposals as $pp)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span class="font-medium">{{ $pp['line']->product->name }} <span class="text-xs font-normal text-slate-500">/ {{ __($pp['line']->productUnit->unit->name) }}</span></span>
                        <span class="tabular-nums">{{ Decimal::format($pp['current']) }} → <strong>{{ Decimal::format($pp['line']->proposed_selling_price) }}</strong>
                            @if ($pp['line']->selling_price_applied_at)<span class="ml-2 rounded-full bg-brand-100 px-2 py-0.5 text-xs font-semibold text-brand-800 dark:bg-brand-900/50 dark:text-brand-100">{{ __('Applied') }}</span>@endif</span>
                    </li>
                @endforeach
            </ul>
        </section>
        @if ($can['prices'])
            <x-ui.confirm-dialog wire:model="confirmingPrices" confirm="applyPrices" return-focus="[data-apply-prices]"
                :title="__('Update catalogue selling prices?')"
                :description="__(':n products will get a new selling price from now on, in every branch. Past sales keep their prices.', ['n' => $pendingProposals])"
                :confirm-label="__('Update prices')" :busy-label="__('Updating…')" />
        @endif
    @endif

    @if ($can['cancel'] || $can['close'] || $can['approve'])
        <section class="card flex flex-col gap-3 md:flex-row md:items-end">
            <x-ui.field class="flex-1" :label="__('Reason (for reject, cancel or close)')" for="po-reason" error="reason"><input id="po-reason" type="text" wire:model="reason" class="form-input"></x-ui.field>
            @if ($can['approve']) <button type="button" wire:click="act('reject')" class="btn btn-secondary">{{ __('Return to draft') }}</button> @endif
            @if ($can['close']) <button type="button" wire:click="act('close')" wire:confirm="{{ __('Close this order? Outstanding quantities will not be received.') }}" class="btn btn-secondary">{{ __('Close order') }}</button> @endif
            @if ($can['cancel']) <button type="button" wire:click="act('cancel')" wire:confirm="{{ __('Cancel this purchase order?') }}" class="btn btn-danger">{{ __('Cancel order') }}</button> @endif
        </section>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        <section>
            <h3 class="mb-3 font-semibold">{{ __('Goods receipts') }}</h3>
            <x-ui.table>
                <x-slot:head><th class="px-4 py-3">{{ __('Reference') }}</th><th class="px-4 py-3">{{ __('Status') }}</th><th class="px-4 py-3 text-right">{{ __('Total') }}</th></x-slot:head>
                @forelse ($receipts as $r)
                    <tr><td class="px-4 py-3"><a href="{{ route('purchasing.receipts.show', $r) }}" class="font-medium text-brand-700 hover:underline dark:text-brand-100">{{ $r->reference }}</a><p class="text-xs text-slate-500">{{ $r->received_date->format('d/m/Y') }} · {{ $r->poster?->name }}</p></td><td class="px-4 py-3"><x-ui.status :status="$r->status" /></td><td class="px-4 py-3 text-right tabular-nums">{{ Decimal::format($r->total) }}</td></tr>
                @empty
                    <x-ui.empty-row colspan="3">{{ __('Nothing received yet.') }}</x-ui.empty-row>
                @endforelse
            </x-ui.table>
        </section>
        <section>
            <h3 class="mb-3 font-semibold">{{ __('Approval audit') }}</h3>
            <ol class="card space-y-3 text-sm">
                @foreach ($events as $e)
                    <li class="flex justify-between gap-3"><span><span class="font-medium">{{ __(ucfirst(str_replace('_', ' ', $e->event))) }}</span> · {{ $e->user?->name ?? '—' }}@if ($e->notes)<span class="block text-xs text-slate-500">{{ $e->notes }}</span>@endif</span><span class="shrink-0 text-xs text-slate-500">{{ $e->created_at->timezone($order->branch->company->timezone)->format('d/m/Y H:i') }}</span></li>
                @endforeach
            </ol>
        </section>
    </div>
</div>
