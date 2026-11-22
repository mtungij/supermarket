@use('App\Support\Decimal')
<div class="space-y-6">
    <x-ui.page-header :title="$sale->sale_number" :description="$sale->sold_at->timezone($tz)->format('d/m/Y H:i').' · '.$sale->branch->name.' · '.$sale->cashier->name">
        <x-slot:actions>
            <a href="{{ route('sales.receipt', $sale) }}" target="_blank" class="btn btn-secondary">{{ __('Print preview') }}</a>
            <button type="button" x-data x-on:click="window.printReceipt(@js(route('sales.receipt', ['sale' => $sale->uuid, 'autoprint' => 1])))" class="btn btn-secondary">{{ __('Reprint receipt') }}</button>
            @if ($can['refund'] && $returnable->contains(fn ($q) => Decimal::isPositive($q)))
                <button type="button" wire:click="startReturn" class="btn btn-primary">{{ __('Return / exchange') }}</button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
    @include('components.ui.flash')

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.stat-card :label="__('Total')" :value="$sale->currency.' '.Decimal::format($sale->total)" :hint="$sale->prices_include_tax ? __('VAT included: :tax', ['tax' => Decimal::format($sale->tax_total)]) : __('VAT: :tax', ['tax' => Decimal::format($sale->tax_total)])" icon="receipt" />
        <x-ui.stat-card :label="__('Customer')" :value="$sale->customer?->name ?? __('Walk-in')" icon="users" />
        <x-ui.stat-card :label="__('Register')" :value="$sale->device?->name ?? '—'" :hint="__('Sold from :location', ['location' => __($sale->location->name)])" icon="device" />
        @if ($can['cost'])
            <x-ui.stat-card :label="__('COGS')" :value="Decimal::format($sale->cost_total)" :hint="__('Gross profit :p', ['p' => Decimal::format(Decimal::sub((string) $sale->net_total, (string) $sale->cost_total))])" icon="chart" />
        @endif
    </div>

    <x-ui.table>
        <x-slot:head><th class="px-4 py-3">{{ __('Product') }}</th><th class="px-4 py-3">{{ __('Quantity') }}</th><th class="px-4 py-3 text-right">{{ __('Unit price') }}</th><th class="px-4 py-3 text-right">{{ __('Discount') }}</th><th class="px-4 py-3 text-right">{{ __('Tax') }}</th><th class="px-4 py-3 text-right">{{ __('Line total') }}</th><th class="px-4 py-3">{{ __('Returnable') }}</th></x-slot:head>
        @foreach ($sale->items as $item)
            <tr>
                <td class="px-4 py-3"><p class="font-medium">{{ $item->product_name }}</p><p class="text-xs text-slate-500">{{ $item->sku }} {{ $item->barcode }}@if ($item->allocations->whereNotNull('batch_id')->isNotEmpty()) · {{ __('Batch') }} {{ $item->allocations->map(fn ($a) => $a->batch?->batch_number)->filter()->join(', ') }}@endif</p></td>
                <td class="px-4 py-3">{{ Decimal::trim($item->quantity) }} {{ __($item->unit_name) }}</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ Decimal::format($item->unit_price) }}</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ Decimal::format($item->discount_amount) }}</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ Decimal::format($item->tax_amount) }} <span class="text-xs text-slate-500">({{ Decimal::trim($item->tax_rate) }}%)</span></td>
                <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ Decimal::format($item->line_total) }}</td>
                <td class="px-4 py-3">{{ Decimal::trim(Decimal::div($returnable[$item->id], (string) $item->conversion_factor, 4)) }} {{ __($item->unit_name) }}</td>
            </tr>
        @endforeach
    </x-ui.table>

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="card">
            <h3 class="mb-3 font-semibold">{{ __('Payments') }}</h3>
            <ul class="space-y-2 text-sm">
                @foreach ($sale->payments as $p)
                    <li class="flex justify-between"><span>{{ __($p->method_name) }}@if ($p->reference) · {{ $p->reference }}@endif <span class="text-xs text-slate-500">{{ __('confirmed manually') }}</span></span><span class="tabular-nums">{{ Decimal::format($p->amount) }}@if (Decimal::isPositive((string) $p->change_given)) <span class="text-xs text-slate-500">({{ __('tendered :t, change :c', ['t' => Decimal::format($p->tendered), 'c' => Decimal::format($p->change_given)]) }})</span>@endif</span></li>
                @endforeach
            </ul>
        </section>
        @if ($movements->isNotEmpty())
            <section class="card">
                <h3 class="mb-3 font-semibold">{{ __('Stock movements') }}</h3>
                <ul class="space-y-2 text-sm">@foreach ($movements as $m)<li class="flex justify-between"><span>{{ __($m->type->label()) }} · {{ __($m->location->name) }}</span><span class="tabular-nums">{{ Decimal::trim($m->quantity) }} @ {{ Decimal::format($m->unit_cost, 2) }}</span></li>@endforeach</ul>
            </section>
        @endif
    </div>

    @if ($returns->isNotEmpty())
        <section>
            <h3 class="mb-3 font-semibold">{{ __('Returns') }}</h3>
            <div class="space-y-3">
                @foreach ($returns as $r)
                    <div class="card text-sm">
                        <div class="flex flex-wrap justify-between gap-2"><p class="font-semibold">{{ $r->return_number }} · {{ $r->returned_at->timezone($tz)->format('d/m/Y H:i') }} · {{ $r->processor->name }}</p><p class="font-semibold tabular-nums">−{{ Decimal::format($r->refund_total) }} ({{ __($r->refund_method_name) }})</p></div>
                        <p class="text-slate-500">{{ $r->reason }}</p>
                        <ul class="mt-2">@foreach ($r->items as $ri)<li>{{ $ri->saleItem->product_name }}: {{ Decimal::trim(Decimal::div((string) $ri->quantity_base, (string) $ri->saleItem->conversion_factor, 4)) }} {{ __($ri->saleItem->unit_name) }} → {{ __($ri->location->name) }} ({{ __($ri->disposition->label()) }})</li>@endforeach</ul>
                        @if ($r->exchangeSale)<p class="mt-2">{{ __('Exchange sale') }}: <a href="{{ route('sales.show', $r->exchangeSale->uuid) }}" class="underline">{{ $r->exchangeSale->sale_number }}</a></p>
                        @elseif ($r->refund_method_type === 'exchange_credit')<p class="mt-2"><a href="{{ route('pos', ['exchange' => $r->uuid]) }}" class="font-medium text-brand-700 underline dark:text-brand-100">{{ __('Use exchange credit in POS') }}</a></p>@endif
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <x-ui.modal wire:model="showReturn" :title="__('Return / exchange')" max-width="max-w-3xl">
        <div class="space-y-4">
            <div class="flex justify-end"><button type="button" wire:click="returnAll" class="btn btn-secondary">{{ __('Return everything remaining') }}</button></div>
            @foreach ($sale->items as $item)
                @if (Decimal::isPositive($returnable[$item->id]))
                    <div class="grid gap-3 rounded-xl border border-slate-200 p-3 sm:grid-cols-3 sm:items-end dark:border-slate-700">
                        <div><p class="font-medium">{{ $item->product_name }}</p><p class="text-xs text-slate-500">{{ __('Up to :qty :unit', ['qty' => Decimal::trim(Decimal::div($returnable[$item->id], (string) $item->conversion_factor, 4)), 'unit' => __($item->unit_name)]) }}</p></div>
                        <x-ui.field :label="__('Quantity returned')" for="rq-{{ $item->id }}" error="returnItems.{{ $item->id }}.quantity"><input id="rq-{{ $item->id }}" type="text" inputmode="decimal" wire:model="returnItems.{{ $item->id }}.quantity" class="form-input"></x-ui.field>
                        <x-ui.field :label="__('Condition')" for="rd-{{ $item->id }}" error="returnItems.{{ $item->id }}.disposition"><select id="rd-{{ $item->id }}" wire:model="returnItems.{{ $item->id }}.disposition" class="form-input">@foreach ($dispositions as $d) <option value="{{ $d->value }}">{{ __($d->label()) }}</option> @endforeach</select></x-ui.field>
                    </div>
                @endif
            @endforeach
            @foreach ($errors->getMessages() as $field => $messages) @if (str_starts_with($field, 'items')) <p class="form-error">{{ $messages[0] }}</p> @endif @endforeach
            <x-ui.field :label="__('Reason')" for="r-reason" error="reason"><input id="r-reason" type="text" wire:model="reason" class="form-input" required></x-ui.field>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field :label="__('Refund method')" for="r-method" error="refundMethod"><select id="r-method" wire:model="refundMethod" class="form-input">@foreach ($refundMethods as $m) <option value="{{ $m->uuid }}">{{ __($m->name) }}{{ $m->type->value === 'exchange_credit' ? ' ('.__('exchange').')' : '' }}</option> @endforeach</select>@error('refund_method') <p class="form-error">{{ $message }}</p> @enderror</x-ui.field>
                <x-ui.field :label="__('Refund reference')" for="r-ref" error="refundReference"><input id="r-ref" type="text" wire:model="refundReference" class="form-input"></x-ui.field>
            </div>
            <p class="text-xs text-slate-500">{{ __('Fit-for-resale goods go back to the selling location; damaged or expired goods go to Damaged / Quarantine. Choose "Exchange credit" to sell replacement items instead of refunding money.') }}</p>
            <div class="flex gap-3"><button type="button" wire:click="processReturn" wire:confirm="{{ __('Record this return? Stock and refunds will be posted.') }}" wire:loading.attr="disabled" class="btn btn-primary">{{ __('Record return') }}</button><button type="button" wire:click="$set('showReturn', false)" class="btn btn-secondary">{{ __('Cancel') }}</button></div>
        </div>
    </x-ui.modal>
</div>
