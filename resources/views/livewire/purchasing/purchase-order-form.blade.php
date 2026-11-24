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

    <section class="card space-y-4" aria-labelledby="po-products">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h3 id="po-products" class="font-semibold">{{ __('Products') }} @if ($lines)<span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs dark:bg-slate-700">{{ count($lines) }}</span>@endif</h3>
            @include('livewire.partials.product-multi-picker')
        </div>
        <details class="rounded-xl border border-slate-200 p-3 dark:border-slate-700" @if ($lines === []) open @endif>
            <summary class="cursor-pointer text-sm font-medium text-slate-600 dark:text-slate-300">{{ __('Scan a barcode instead') }}</summary>
            <div class="mt-3">@include('livewire.partials.product-picker')</div>
        </details>
        @error('lines') <p class="form-error">{{ $message }}</p> @enderror
        <p class="text-xs text-slate-500">{{ __('Creating a purchase does not change stock or selling prices. Stock increases when goods are received; new selling prices are applied separately from the purchase page.') }}</p>

        @if ($lines === [])
            <div class="rounded-xl border border-dashed border-slate-300 px-4 py-10 text-center text-slate-500 dark:border-slate-600">{{ __('Use "Select products" to add several products at once, or scan a barcode.') }}</div>
        @else
            {{-- Wide screens (xl+): editable table; reference values sit under each input --}}
            <div class="hidden max-h-[70vh] overflow-auto rounded-xl border border-slate-200 xl:block dark:border-slate-700" data-lines-table>
                <table class="w-full text-sm">
                    <thead class="sticky top-0 z-10 bg-slate-50 text-left text-xs uppercase tracking-wider text-slate-500 dark:bg-slate-800"><tr>
                        <th class="px-2 py-2">{{ __('Product') }} / {{ __('In stock') }}</th>
                        <th class="px-2 py-2">{{ __('Unit') }}</th>
                        <th class="px-2 py-2">{{ __('Quantity') }}</th>
                        <th class="px-2 py-2">{{ __('Buying price') }}<span class="block font-normal normal-case tracking-normal">{{ __('current below') }}</span></th>
                        <th class="px-2 py-2">{{ __('Discount') }} / {{ __('Tax %') }}</th>
                        <th class="px-2 py-2">{{ __('New selling') }}<span class="block font-normal normal-case tracking-normal">{{ __('current below') }}</span></th>
                        <th class="px-2 py-2 text-right">{{ __('Line total') }}</th>
                        <th class="px-2 py-2"><span class="sr-only">{{ __('Remove') }}</span></th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                        @foreach ($lines as $i => $line)
                            @php($p = $products[$line['product_id']] ?? null)
                            @php($in = $info[$i] ?? null)
                            <tr wire:key="pol-{{ $i }}-{{ $line['product_id'] }}" class="align-top" data-line-row>
                                <td class="min-w-40 px-2 py-2"><p class="font-medium [overflow-wrap:anywhere]">{{ $p?->name }}</p><p class="text-xs text-slate-500 [overflow-wrap:anywhere]">{{ $p?->sku }}@if ($p?->barcodes->first()) · {{ $p->barcodes->first()->barcode }}@endif</p><p class="text-xs text-slate-600 dark:text-slate-300">{{ __('In stock') }}: <span class="tabular-nums">{{ $in['stock'] ?? '0' }}</span> {{ __($p?->baseUnit?->name ?? '') }}</p></td>
                                <td class="px-2 py-2"><select wire:model.live="lines.{{ $i }}.product_unit_id" class="form-input w-28" aria-label="{{ __('Unit') }}">@foreach ($this->unitsFor($line['product_id'])->where('can_purchase', true) as $u) <option value="{{ $u->id }}">{{ __($u->unit->name) }}{{ $u->is_base ? '' : ' ×'.\App\Support\Decimal::trim($u->factor) }}</option> @endforeach</select></td>
                                <td class="px-2 py-2"><input type="text" inputmode="decimal" wire:model.live.debounce.500ms="lines.{{ $i }}.quantity" class="form-input w-20" aria-label="{{ __('Quantity') }}">@error("lines.$i.quantity") <p class="form-error">{{ $message }}</p> @enderror</td>
                                <td class="px-2 py-2"><div class="relative"><span class="pointer-events-none absolute left-2 top-1/2 -translate-y-1/2 text-[10px] font-semibold text-slate-500">{{ $currency }}</span><input type="text" inputmode="decimal" wire:model.live.debounce.500ms="lines.{{ $i }}.unit_cost" class="form-input w-32 pl-9" aria-label="{{ __('Buying price') }}"></div><p class="mt-1 text-xs text-slate-500">/ {{ $in['unit_name'] ?? '' }} · {{ __('Current') }}: <span class="tabular-nums">{{ $in && $in['buying'] !== null ? \App\Support\Decimal::format($in['buying']) : '—' }}</span>@if (($in['buying_source'] ?? '') === 'reference') ({{ __('reference') }})@endif</p>@error("lines.$i.unit_cost") <p class="form-error">{{ $message }}</p> @enderror</td>
                                <td class="px-2 py-2"><div class="flex gap-1"><input type="text" inputmode="decimal" wire:model.live.debounce.500ms="lines.{{ $i }}.discount_amount" class="form-input w-20" aria-label="{{ __('Discount') }}"><input type="text" inputmode="decimal" wire:model.live.debounce.500ms="lines.{{ $i }}.tax_rate" class="form-input w-16" aria-label="{{ __('Tax %') }}"></div>@error("lines.$i.discount_amount") <p class="form-error">{{ $message }}</p> @enderror @error("lines.$i.tax_rate") <p class="form-error">{{ $message }}</p> @enderror</td>
                                <td class="px-2 py-2"><div class="relative"><span class="pointer-events-none absolute left-2 top-1/2 -translate-y-1/2 text-[10px] font-semibold text-slate-500">{{ $currency }}</span><input type="text" inputmode="decimal" wire:model.blur="lines.{{ $i }}.proposed_selling_price" class="form-input w-32 pl-9" placeholder="{{ __('No change') }}" @disabled(! ($in['sellable'] ?? true)) aria-label="{{ __('New selling price (optional)') }}"></div><p class="mt-1 text-xs text-slate-500">/ {{ $in['unit_name'] ?? '' }} · {{ __('Now') }}: <span class="tabular-nums">{{ $in && $in['selling'] !== null ? \App\Support\Decimal::format($in['selling']) : '—' }}</span></p>@error("lines.$i.proposed_selling_price") <p class="form-error">{{ $message }}</p> @enderror</td>
                                <td class="px-2 py-2 pt-4 text-right font-semibold tabular-nums">{{ $this->lineTotal($line) }}</td>
                                <td class="px-2 py-2"><button type="button" wire:click="removeLine({{ $i }})" class="grid size-10 place-items-center rounded-lg text-red-600 hover:bg-red-50 dark:hover:bg-red-950/40" aria-label="{{ __('Remove') }} {{ $p?->name }}"><x-icon name="x" /></button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Phones / tablets / small laptops: editable cards --}}
            <div class="grid gap-3 md:grid-cols-2 xl:hidden" data-lines-cards>
                @foreach ($lines as $i => $line)
                    @php($p = $products[$line['product_id']] ?? null)
                    @php($in = $info[$i] ?? null)
                    <div wire:key="polc-{{ $i }}-{{ $line['product_id'] }}" class="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0"><p class="font-semibold [overflow-wrap:anywhere]">{{ $p?->name }}</p><p class="text-xs text-slate-500">{{ $p?->sku }} · {{ __('In stock') }}: {{ $in['stock'] ?? '0' }} {{ __($p?->baseUnit?->name ?? '') }}</p></div>
                            <button type="button" wire:click="removeLine({{ $i }})" class="grid size-11 shrink-0 place-items-center rounded-lg text-red-600 hover:bg-red-50 dark:hover:bg-red-950/40" aria-label="{{ __('Remove') }} {{ $p?->name }}"><x-icon name="x" /></button>
                        </div>
                        <div class="mt-3 grid grid-cols-2 gap-3">
                            <div><label class="form-label" for="pou-{{ $i }}">{{ __('Unit') }}</label><select id="pou-{{ $i }}" wire:model.live="lines.{{ $i }}.product_unit_id" class="form-input">@foreach ($this->unitsFor($line['product_id'])->where('can_purchase', true) as $u) <option value="{{ $u->id }}">{{ __($u->unit->name) }}{{ $u->is_base ? '' : ' ×'.\App\Support\Decimal::trim($u->factor) }}</option> @endforeach</select></div>
                            <div><label class="form-label" for="poq-{{ $i }}">{{ __('Quantity') }}</label><input id="poq-{{ $i }}" type="text" inputmode="decimal" wire:model.live.debounce.500ms="lines.{{ $i }}.quantity" class="form-input">@error("lines.$i.quantity") <p class="form-error">{{ $message }}</p> @enderror</div>
                            <div><label class="form-label" for="poc-{{ $i }}">{{ __('Buying price') }}</label><div class="relative"><span class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-[11px] font-semibold text-slate-500">{{ $currency }}</span><input id="poc-{{ $i }}" type="text" inputmode="decimal" wire:model.live.debounce.500ms="lines.{{ $i }}.unit_cost" class="form-input pl-10"></div><p class="mt-1 text-xs text-slate-500">/ {{ $in['unit_name'] ?? '' }} · {{ __('Current') }}: {{ $in && $in['buying'] !== null ? \App\Support\Decimal::format($in['buying']) : '—' }}</p>@error("lines.$i.unit_cost") <p class="form-error">{{ $message }}</p> @enderror</div>
                            <div><label class="form-label" for="pos-{{ $i }}">{{ __('New selling') }}</label><div class="relative"><span class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-[11px] font-semibold text-slate-500">{{ $currency }}</span><input id="pos-{{ $i }}" type="text" inputmode="decimal" wire:model.blur="lines.{{ $i }}.proposed_selling_price" class="form-input pl-10" placeholder="{{ __('No change') }}" @disabled(! ($in['sellable'] ?? true))></div><p class="mt-1 text-xs text-slate-500">/ {{ $in['unit_name'] ?? '' }} · {{ __('Now') }}: {{ $in && $in['selling'] !== null ? \App\Support\Decimal::format($in['selling']) : '—' }}</p>@error("lines.$i.proposed_selling_price") <p class="form-error">{{ $message }}</p> @enderror</div>
                            <div><label class="form-label" for="pod-{{ $i }}">{{ __('Discount') }}</label><input id="pod-{{ $i }}" type="text" inputmode="decimal" wire:model.live.debounce.500ms="lines.{{ $i }}.discount_amount" class="form-input"></div>
                            <div><label class="form-label" for="pot-{{ $i }}">{{ __('Tax %') }}</label><input id="pot-{{ $i }}" type="text" inputmode="decimal" wire:model.live.debounce.500ms="lines.{{ $i }}.tax_rate" class="form-input"></div>
                        </div>
                        <p class="mt-2 text-right text-sm">{{ __('Line total') }}: <span class="font-semibold tabular-nums">{{ $this->lineTotal($line) }}</span></p>
                    </div>
                @endforeach
            </div>
        @endif
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
