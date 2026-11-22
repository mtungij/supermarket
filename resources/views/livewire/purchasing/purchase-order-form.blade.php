<div class="space-y-6">
    <x-ui.page-header :title="$orderUuid ? __('Edit purchase') : __('Create purchase')" :description="__('Scan or search products. Save as a draft, or send the PO when ready.')" />
    @include('components.ui.flash')

    <section class="card grid gap-5 md:grid-cols-4">
        <x-ui.field :label="__('Supplier')" for="po-supplier" error="supplier_id">
            <select id="po-supplier" wire:model="supplier" class="form-input"><option value="">{{ __('Select…') }}</option>@foreach ($suppliers as $s) <option value="{{ $s->uuid }}">{{ $s->name }}</option> @endforeach</select>
            @error('supplier') <p class="form-error">{{ $message }}</p> @enderror
        </x-ui.field>
        <x-ui.field :label="__('Branch')" for="po-branch" error="branch_id">
            <select id="po-branch" wire:model="branch" class="form-input"><option value="">{{ __('Select…') }}</option>@foreach ($branches as $b) <option value="{{ $b->uuid }}">{{ $b->name }}</option> @endforeach</select>
            @error('branch') <p class="form-error">{{ $message }}</p> @enderror
        </x-ui.field>
        <x-ui.field :label="__('Order date')" for="po-date" error="orderDate"><input id="po-date" type="date" wire:model="orderDate" class="form-input"></x-ui.field>
        <x-ui.field :label="__('Expected delivery')" for="po-expected" error="expected_date"><input id="po-expected" type="date" wire:model="expectedDate" class="form-input">@error('expectedDate') <p class="form-error">{{ $message }}</p> @enderror</x-ui.field>
        <x-ui.field class="md:col-span-4" :label="__('Notes')" for="po-notes" error="notes"><textarea id="po-notes" wire:model="notes" rows="2" class="form-input"></textarea></x-ui.field>
    </section>

    <section class="card space-y-4">
        <h3 class="font-semibold">{{ __('Products') }}</h3>
        @include('livewire.partials.product-picker')
        @error('lines') <p class="form-error">{{ $message }}</p> @enderror

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wider text-slate-500"><tr>
                    <th class="py-2 pr-3">{{ __('Product') }}</th><th class="py-2 pr-3">{{ __('Unit') }}</th><th class="py-2 pr-3">{{ __('Quantity') }}</th>
                    <th class="py-2 pr-3">{{ __('Unit cost') }}</th><th class="py-2 pr-3">{{ __('Discount') }}</th><th class="py-2 pr-3">{{ __('Tax %') }}</th><th class="py-2 pr-3 text-right">{{ __('Line total') }}</th><th></th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                    @foreach ($lines as $i => $line)
                        <tr wire:key="pol-{{ $i }}-{{ $line['product_id'] }}" class="align-top">
                            <td class="py-2 pr-3 font-medium">{{ $products[$line['product_id']]->name ?? '' }}</td>
                            <td class="py-2 pr-3"><select wire:model.live="lines.{{ $i }}.product_unit_id" class="form-input min-w-32">@foreach ($this->unitsFor($line['product_id'])->where('can_purchase', true) as $u) <option value="{{ $u->id }}">{{ __($u->unit->name) }}{{ $u->is_base ? '' : ' ×'.\App\Support\Decimal::trim($u->factor) }}</option> @endforeach</select></td>
                            <td class="py-2 pr-3"><input type="text" inputmode="decimal" wire:model.live.debounce.500ms="lines.{{ $i }}.quantity" class="form-input w-28">@error("lines.$i.quantity") <p class="form-error">{{ $message }}</p> @enderror</td>
                            <td class="py-2 pr-3"><input type="text" inputmode="decimal" wire:model.live.debounce.500ms="lines.{{ $i }}.unit_cost" class="form-input w-32">@error("lines.$i.unit_cost") <p class="form-error">{{ $message }}</p> @enderror</td>
                            <td class="py-2 pr-3"><input type="text" inputmode="decimal" wire:model.live.debounce.500ms="lines.{{ $i }}.discount_amount" class="form-input w-28">@error("lines.$i.discount_amount") <p class="form-error">{{ $message }}</p> @enderror</td>
                            <td class="py-2 pr-3"><input type="text" inputmode="decimal" wire:model.live.debounce.500ms="lines.{{ $i }}.tax_rate" class="form-input w-20">@error("lines.$i.tax_rate") <p class="form-error">{{ $message }}</p> @enderror</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $this->lineTotal($line) }}</td>
                            <td class="py-2"><button type="button" wire:click="removeLine({{ $i }})" class="rounded-lg p-2 text-red-600 hover:bg-red-50" aria-label="{{ __('Remove') }}"><x-icon name="x" /></button></td>
                        </tr>
                    @endforeach
                    @if ($lines === [])
                        <tr><td colspan="8" class="py-8 text-center text-slate-500">{{ __('Scan or search to add products.') }}</td></tr>
                    @endif
                </tbody>
            </table>
        </div>
    </section>

    <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <x-ui.stat-card :label="__('Products')" :value="$totals['items']" icon="box" />
        <x-ui.stat-card :label="__('Subtotal')" :value="\App\Support\Decimal::format($totals['subtotal'])" icon="receipt" />
        <x-ui.stat-card :label="__('Discount')" :value="\App\Support\Decimal::format($totals['discount'])" icon="tag" />
        <x-ui.stat-card :label="__('Tax')" :value="\App\Support\Decimal::format($totals['tax'])" icon="chart" />
        <x-ui.stat-card :label="__('Grand total')" :value="$currency.' '.\App\Support\Decimal::format($totals['total'])" icon="wallet" />
    </section>

    <div class="flex flex-col gap-3 sm:flex-row">
        <button type="button" wire:click="save(false)" class="btn btn-secondary" wire:loading.attr="disabled" wire:target="save">{{ __('Save draft') }}</button>
        <button type="button" wire:click="save(true)" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">{{ __('Save & send PO') }}</button>
        <a href="{{ route('purchasing.orders.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
        <span wire:loading wire:target="save" class="self-center text-sm text-slate-500">{{ __('Saving…') }}</span>
    </div>
</div>
