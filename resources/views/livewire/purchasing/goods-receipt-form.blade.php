@use('App\Support\Decimal')
<div class="space-y-6">
    <x-ui.page-header :title="__('Receive purchase')" :description="__('Enter what actually arrived, or press Receive All. Posting adds stock immediately and cannot be edited afterwards (corrections use stock adjustments).')">
        <x-slot:actions>
            <a href="{{ route('purchasing.orders.show', $order) }}" class="btn btn-secondary">{{ __('Purchase details') }}</a>
        </x-slot:actions>
    </x-ui.page-header>
    @include('components.ui.flash')
    @foreach (['status', 'lines', 'location_id', 'received_date', 'supplier_invoice_number', 'expires_on', 'manufactured_on', 'batch_number', 'document', 'quantity'] as $f)
        @error($f) <div class="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200" role="alert">{{ $message }}</div> @enderror
    @endforeach
    @if ($quick)
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-900/30 dark:text-amber-100" role="status">{{ __('Quick receive: this :status purchase will be approved by you as part of posting. The approval is recorded in the audit trail.', ['status' => __($order->status->label())]) }}</div>
    @endif

    {{-- Purchase summary --}}
    <section class="grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
        <div class="card p-4"><p class="text-xs text-slate-500">{{ __('Purchase number') }}</p><p class="font-semibold">{{ $order->reference }}</p></div>
        <div class="card p-4"><p class="text-xs text-slate-500">{{ __('Supplier') }}</p><p class="truncate font-semibold">{{ $order->supplier->name }}</p></div>
        <div class="card p-4"><p class="text-xs text-slate-500">{{ __('Branch') }}</p><p class="font-semibold">{{ $order->branch->name }}</p></div>
        <div class="card p-4"><p class="text-xs text-slate-500">{{ __('Purchase date') }}</p><p class="font-semibold">{{ $order->order_date->format('d/m/Y') }}</p></div>
        <div class="card p-4"><p class="text-xs text-slate-500">{{ __('Ordered by') }}</p><p class="font-semibold">{{ $order->creator?->name ?? '—' }}</p></div>
        <div class="card p-4"><p class="text-xs text-slate-500">{{ __('Purchase status') }}</p><x-ui.status :status="$order->status" /></div>
        <div class="card p-4"><p class="text-xs text-slate-500">{{ __('Total ordered') }}</p><p class="font-semibold tabular-nums">{{ Decimal::trim($totals['ordered']) }}</p></div>
        <div class="card p-4"><p class="text-xs text-slate-500">{{ __('Previously received') }}</p><p class="font-semibold tabular-nums">{{ Decimal::trim($totals['previous']) }}</p></div>
        <div class="card p-4"><p class="text-xs text-slate-500">{{ __('Remaining') }}</p><p class="font-semibold tabular-nums">{{ Decimal::trim($totals['remaining']) }}</p></div>
        <div class="card p-4"><p class="text-xs text-slate-500">{{ __('Quantities in') }}</p><p class="text-sm">{{ __('base units') }}</p></div>
    </section>

    {{-- Receipt header --}}
    <section class="card grid gap-5 md:grid-cols-3">
        <x-ui.field :label="__('GRN reference')" for="grn-ref" :hint="__('Assigned when posted.')"><input id="grn-ref" type="text" value="{{ $nextReference }}" class="form-input font-mono" disabled></x-ui.field>
        <x-ui.field :label="__('Receiving date')" for="grn-date" error="receivedDate"><input id="grn-date" type="date" wire:model="receivedDate" class="form-input"></x-ui.field>
        <x-ui.field :label="__('Default receiving location')" for="grn-location" error="defaultLocation" :hint="__('Applies to all lines; each line can be changed.')">
            <select id="grn-location" wire:model.live="defaultLocation" class="form-input">@foreach ($locations as $l) <option value="{{ $l->uuid }}">{{ __($l->name) }}</option> @endforeach</select>
        </x-ui.field>
        <x-ui.field :label="__('Supplier delivery note no.')" for="grn-dn" error="deliveryNote"><input id="grn-dn" type="text" wire:model="deliveryNote" class="form-input"></x-ui.field>
        <x-ui.field :label="__('Supplier invoice no.')" for="grn-inv" error="invoiceNumber"><input id="grn-inv" type="text" wire:model="invoiceNumber" class="form-input"></x-ui.field>
        <x-ui.field :label="__('Notes')" for="grn-notes" error="notes"><input id="grn-notes" type="text" wire:model="notes" class="form-input"></x-ui.field>
    </section>

    {{-- Items --}}
    <section class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h3 class="text-lg font-semibold">{{ __('Items') }}</h3>
            <div class="flex gap-2">
                <button type="button" wire:click="clearQuantities" class="btn btn-secondary">{{ __('Clear') }}</button>
                <button type="button" wire:click="receiveAll" wire:loading.attr="disabled" wire:target="receiveAll" class="btn btn-primary"><x-icon name="box" /> {{ __('Receive All') }}</button>
            </div>
        </div>

        @foreach ($lines as $i => $line)
            @php
                $row = $rows[$i];
                $ol = $row['order_line'];
                $p = $ol->product;
                $tracked = $p->track_batches || $p->track_expiry;
            @endphp
            <div wire:key="grl-{{ $line['purchase_order_line_id'] }}" @class(['card space-y-4', 'border-brand-500' => Decimal::isPositive($row['qty_base'])])>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="font-semibold">{{ $p->name }}</p>
                        <p class="text-xs text-slate-500">{{ $p->sku }} · {{ __('Ordered') }}: {{ Decimal::trim($ol->quantity) }} {{ __($ol->productUnit->unit->name) }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2 text-xs">
                        <x-ui.badge>{{ __('Ordered') }} {{ Decimal::trim($ol->quantity_base) }}</x-ui.badge>
                        <x-ui.badge color="blue">{{ __('Previously received') }} {{ Decimal::trim($ol->received_quantity_base) }}</x-ui.badge>
                        <x-ui.badge color="amber">{{ __('Remaining') }} {{ Decimal::trim($ol->remainingBase()) }} {{ __($p->baseUnit->name) }}</x-ui.badge>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3 md:grid-cols-6">
                    <x-ui.field :label="__('Unit')" for="u-{{ $i }}" error="lines.{{ $i }}.product_unit_id">
                        <select id="u-{{ $i }}" wire:model.live="lines.{{ $i }}.product_unit_id" class="form-input">@foreach ($unitOptions[$p->id] ?? [] as $u) <option value="{{ $u->id }}">{{ __($u->unit->name) }}{{ $u->is_base ? '' : ' ×'.Decimal::trim($u->factor) }}</option> @endforeach</select>
                    </x-ui.field>
                    <x-ui.field :label="__('Received qty')" for="q-{{ $i }}" error="lines.{{ $i }}.quantity">
                        <input id="q-{{ $i }}" type="text" inputmode="decimal" wire:model.live.debounce.400ms="lines.{{ $i }}.quantity" class="form-input text-lg font-semibold" placeholder="0">
                        @if ($row['error']) <p class="form-error">{{ $row['error'] }}</p> @endif
                    </x-ui.field>
                    <div>
                        <p class="form-label">{{ __('Stock increase') }}</p>
                        <p class="flex min-h-12 items-center text-lg font-semibold tabular-nums">+{{ Decimal::trim($row['qty_base']) }} <span class="ml-1 text-sm font-normal text-slate-500">{{ __($p->baseUnit->name) }}</span></p>
                    </div>
                    <x-ui.field :label="__('Unit buying price')" for="c-{{ $i }}" error="lines.{{ $i }}.unit_cost">
                        <input id="c-{{ $i }}" type="text" inputmode="decimal" wire:model.live.debounce.400ms="lines.{{ $i }}.unit_cost" class="form-input">
                    </x-ui.field>
                    <x-ui.field class="col-span-2" :label="__('Receiving location')" for="l-{{ $i }}" error="lines.{{ $i }}.location">
                        <select id="l-{{ $i }}" wire:model="lines.{{ $i }}.location" class="form-input">@foreach ($locations as $l) <option value="{{ $l->uuid }}">{{ __($l->name) }}</option> @endforeach</select>
                        @error("lines.$i.location_id") <p class="form-error">{{ $message }}</p> @enderror
                    </x-ui.field>
                    @if ($tracked)
                        <x-ui.field :label="__('Batch no.').($p->track_batches ? ' *' : '')" for="b-{{ $i }}" error="lines.{{ $i }}.batch_number"><input id="b-{{ $i }}" type="text" wire:model="lines.{{ $i }}.batch_number" class="form-input uppercase"></x-ui.field>
                        <x-ui.field :label="__('Mfg date')" for="m-{{ $i }}" error="lines.{{ $i }}.manufactured_on"><input id="m-{{ $i }}" type="date" wire:model="lines.{{ $i }}.manufactured_on" class="form-input"></x-ui.field>
                        <x-ui.field :label="__('Expiry date').($p->track_expiry ? ' *' : '')" for="e-{{ $i }}" error="lines.{{ $i }}.expires_on"><input id="e-{{ $i }}" type="date" wire:model="lines.{{ $i }}.expires_on" class="form-input"></x-ui.field>
                    @endif
 <x-ui.field :class="$tracked ? 'col-span-2 md:col-span-3' : 'col-span-2 md:col-span-6'" :label="__('Line notes')" for="n-{{ $i }}" error="lines.{{ $i }}.notes"><input id="n-{{ $i }}" type="text" wire:model="lines.{{ $i }}.notes" class="form-input"></x-ui.field>
                </div>

                @if (Decimal::isPositive($row['qty_base']))
                    <p class="text-xs text-slate-500">{{ __('Goods value') }} {{ Decimal::format($row['goods_value']) }} · {{ __('Additional costs') }} {{ Decimal::format($row['landed']) }} · {{ __('Landed cost per :unit', ['unit' => __($p->baseUnit->name)]) }} {{ Decimal::format($row['landed_unit'], 4) }}</p>
                @endif
            </div>
        @endforeach
    </section>

    {{-- Additional (landed) costs --}}
    <section class="card space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="font-semibold">{{ __('Additional costs (optional)') }}</h3>
                <p class="text-sm text-slate-500">{{ __('Excluding VAT. Added to inventory cost, split by received quantity. Not added to the supplier balance.') }}</p>
            </div>
            <button type="button" wire:click="addCost" class="btn btn-secondary"><x-icon name="plus" /> {{ __('Add cost') }}</button>
        </div>
        @foreach ($costs as $c => $cost)
            <div wire:key="cost-{{ $c }}" class="grid grid-cols-2 gap-3 md:grid-cols-5 md:items-end">
                <x-ui.field :label="__('Cost type')" for="ct-{{ $c }}" error="costs.{{ $c }}.cost_type"><select id="ct-{{ $c }}" wire:model="costs.{{ $c }}.cost_type" class="form-input">@foreach ($costTypes as $key => $label) <option value="{{ $key }}">{{ __($label) }}</option> @endforeach</select></x-ui.field>
                <x-ui.field class="col-span-2" :label="__('Description')" for="cd-{{ $c }}" error="costs.{{ $c }}.description"><input id="cd-{{ $c }}" type="text" wire:model="costs.{{ $c }}.description" class="form-input"></x-ui.field>
                <x-ui.field :label="__('Amount')" for="ca-{{ $c }}" error="costs.{{ $c }}.amount"><input id="ca-{{ $c }}" type="text" inputmode="decimal" wire:model.live.debounce.400ms="costs.{{ $c }}.amount" class="form-input"></x-ui.field>
                <button type="button" wire:click="removeCost({{ $c }})" class="min-h-12 text-sm font-medium text-red-600">{{ __('Remove') }}</button>
            </div>
        @endforeach
    </section>

    {{-- Receiving summary --}}
    <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-live="polite">
        <x-ui.stat-card :label="__('Selected products')" :value="$summary['selected_products']" icon="box" />
        <x-ui.stat-card :label="__('Total received quantity')" :value="$summary['received_quantity']" :hint="__('base units')" icon="truck" />
        <x-ui.stat-card :label="__('Goods purchase value')" :value="Decimal::format($summary['goods_value'])" :hint="__('excl. tax')" icon="receipt" />
        <x-ui.stat-card :label="__('Additional costs')" :value="Decimal::format($summary['additional_costs'])" icon="wallet" />
        <x-ui.stat-card :label="__('Landed cost per unit')" :value="$summary['landed_cost_per_unit'] !== null ? Decimal::format($summary['landed_cost_per_unit'], 4) : '—'" :hint="$summary['landed_cost_per_unit'] === null ? __('Shown when all items share one base unit') : null" icon="chart" />
        <x-ui.stat-card :label="__('Total landed value')" :value="$currency.' '.Decimal::format($summary['landed_value'])" icon="wallet" />
        <x-ui.stat-card :label="__('Receiving locations')" :value="$summary['locations']" icon="building" />
        <x-ui.stat-card :label="__('Remaining after receipt')" :value="$summary['remaining_after']" :hint="__('base units')" icon="shield" />
    </section>

    <div class="sticky bottom-0 z-10 -mx-4 flex flex-col gap-3 border-t border-slate-200 bg-white/95 px-4 py-4 backdrop-blur sm:flex-row sm:items-center dark:border-slate-700 dark:bg-slate-900/95">
        <button type="button" wire:click="save" wire:confirm="{{ __('Post this goods receipt? Stock will be updated.') }}" wire:loading.attr="disabled" wire:target="save" class="btn btn-primary" @disabled($summary['selected_products'] === 0)>
            <span wire:loading.remove wire:target="save">{{ __('Post goods receipt') }}</span><span wire:loading wire:target="save">{{ __('Posting…') }}</span>
        </button>
        <a href="{{ route('purchasing.orders.show', $order) }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
        <p class="text-sm text-slate-500 sm:ml-auto">{{ __(':n products · :value', ['n' => $summary['selected_products'], 'value' => $currency.' '.Decimal::format($summary['landed_value'])]) }}</p>
    </div>
</div>
