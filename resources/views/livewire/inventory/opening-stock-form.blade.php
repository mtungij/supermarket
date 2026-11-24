@use('App\Support\Decimal')
<div>
    <x-ui.page-header
        :title="$document ? __('Opening stock :ref', ['ref' => $document->reference]) : __('New opening stock')"
        :description="__('Stock you already had before using this system. No purchase order, supplier or goods receipt is created.')">
        <x-slot:actions>
            @if ($document) <x-ui.status :status="$document->status" /> @endif
            <a href="{{ route('inventory.opening-stock.index') }}" class="btn btn-secondary">{{ __('All opening stock') }}</a>
        </x-slot:actions>
    </x-ui.page-header>
    @include('components.ui.flash')
    @foreach (['status', 'lines', 'location', 'zero_cost_reason', 'document', 'batch', 'quantity'] as $f)
        @error($f) <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-900/30 dark:text-red-200" role="alert" data-form-error>{{ $message }}</div> @enderror
    @endforeach

    {{-- Live summary --}}
    <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-5" data-opening-summary>
        @php($cards = [
            [__('Products'), $document && ! $editable ? $document->line_count : $summary['products']],
            [__('Total quantity'), Decimal::trim(Decimal::round($document && ! $editable ? (string) $document->total_quantity_base : $summary['quantity'], Decimal::QTY))],
            [__('Opening value'), $currency.' '.Decimal::format($document && ! $editable ? (string) $document->total_value : $summary['value'])],
            [__('Branch'), $selectedBranch?->name ?? '—'],
            [__('Stock location'), $selectedLocation ? __($selectedLocation->name) : '—'],
        ])
        @foreach ($cards as [$label, $value])
            <div class="card p-4 {{ $loop->index === 2 ? 'col-span-2 lg:col-span-1' : '' }}">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</p>
                <p class="mt-1 truncate text-lg font-semibold tabular-nums">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    @if ($editable)
        {{-- No <form>: the scan input must own its Enter key (see product-picker). --}}
        <div class="space-y-5">
            @if ($document?->source === 'import')
                <p class="rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-600 dark:bg-slate-800 dark:text-slate-300" data-imported-note>{{ __('Imported from') }} <span class="font-medium [overflow-wrap:anywhere]">{{ $document->import_filename }}</span> · {{ __('Draft: stock has not changed yet.') }}</p>
            @endif
            <section class="card grid gap-5 md:grid-cols-2 lg:grid-cols-4" aria-label="{{ __('Opening stock details') }}">
                <x-ui.field :label="__('Branch').' *'" for="os-branch" error="branch">
                    <select id="os-branch" wire:model.live="branch" class="form-input">@foreach ($branches as $b) <option value="{{ $b->uuid }}">{{ $b->name }}</option> @endforeach</select>
                </x-ui.field>
                <x-ui.field :label="__('Stock location').' *'" for="os-location" error="location"
                            :hint="$selectedLocation ? ($selectedLocation->is_sellable ? __('Sellable: the POS can sell this stock as soon as it is posted.') : __('Not sellable: transfer it to the sales floor before selling.')) : null">
                    <select id="os-location" wire:model.live="location" class="form-input">
                        <option value="">{{ __('Select…') }}</option>
                        @foreach ($locations as $l) <option value="{{ $l->uuid }}">{{ __($l->name) }}{{ $l->is_sellable ? ' · '.__('Sellable') : '' }}</option> @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field :label="__('Opening date').' *'" for="os-date" error="openingDate">
                    <input id="os-date" type="date" wire:model="openingDate" max="{{ now()->toDateString() }}" class="form-input">
                </x-ui.field>
                <x-ui.field :label="__('Reference')" for="os-ref">
                    <input id="os-ref" type="text" value="{{ $document?->reference ?? __('Generated when saved') }}" class="form-input bg-slate-50 dark:bg-slate-800" readonly>
                </x-ui.field>
                <x-ui.field class="md:col-span-2 lg:col-span-4" :label="__('Notes')" for="os-notes" error="notes">
                    <textarea id="os-notes" wire:model="notes" rows="2" class="form-input" placeholder="{{ __('e.g. Stock count of 1 December before going live') }}"></textarea>
                </x-ui.field>
            </section>

            <section class="card space-y-4" aria-labelledby="os-products">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h3 id="os-products" class="font-semibold">{{ __('Products') }} @if ($lines)<span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs dark:bg-slate-700">{{ count($lines) }}</span>@endif</h3>
                    @include('livewire.partials.product-multi-picker')
                </div>
                <details class="rounded-xl border border-slate-200 p-3 dark:border-slate-700" @if ($lines === []) open @endif>
                    <summary class="cursor-pointer text-sm font-medium text-slate-600 dark:text-slate-300">{{ __('Scan a barcode instead') }}</summary>
                    <div class="mt-3">@include('livewire.partials.product-picker')</div>
                </details>
                @error('lines') <p class="form-error">{{ $message }}</p> @enderror

                @if ($lines === [])
                    <div class="rounded-xl border border-dashed border-slate-300 px-4 py-10 text-center text-slate-500 dark:border-slate-600">{{ __('Use "Select products" to add several products at once, or scan a barcode.') }}</div>
                @else
                    {{-- One markup: a labelled card on phones, a table row on desktop (sticky header). --}}
                    <div class="xl:max-h-[70vh] xl:overflow-auto xl:rounded-xl xl:border xl:border-slate-200 dark:xl:border-slate-700" data-lines-table>
                        <div class="sticky top-0 z-10 hidden gap-2 bg-slate-50 px-3 py-2 text-xs font-semibold uppercase tracking-wider text-slate-500 xl:grid xl:grid-cols-[minmax(0,1fr)_7.5rem_5.5rem_8.5rem_8.5rem_7rem_2.75rem] dark:bg-slate-800" aria-hidden="true">
                            <span>{{ __('Product') }} / {{ __('In stock here') }}</span><span>{{ __('Unit') }}</span><span>{{ __('Quantity') }}</span>
                            <span>{{ __('Cost per unit') }} *</span><span>{{ __('New selling') }}</span><span class="text-right">{{ __('Value') }}</span><span></span>
                        </div>
                        <div class="space-y-3 xl:space-y-0 xl:divide-y xl:divide-slate-100 dark:xl:divide-slate-700">
                        @foreach ($lines as $i => $line)
                            @php($p = $products[$line['product_id']] ?? null)
                            @php($r = $rows[$i])
                            <div wire:key="osl-{{ $i }}-{{ $line['product_id'] }}" class="grid grid-cols-2 gap-3 rounded-xl border border-slate-200 p-3 xl:items-start xl:gap-2 xl:rounded-none xl:border-0 xl:px-3 xl:py-2 xl:grid-cols-[minmax(0,1fr)_7.5rem_5.5rem_8.5rem_8.5rem_7rem_2.75rem] dark:border-slate-700 @error("lines.$i.product_id") border-red-400 dark:border-red-700 @enderror" data-opening-line>
                                <div class="col-span-2 min-w-0 xl:col-span-1">
                                    <p class="font-semibold [overflow-wrap:anywhere]">{{ $p?->name }}</p>
                                    <p class="text-xs text-slate-500 [overflow-wrap:anywhere]">{{ $p?->sku }}@if ($p?->barcodes->first()) · {{ $p->barcodes->first()->barcode }}@endif @if ($p?->requiresBatch()) · {{ $p->track_batches ? __('Batch tracked') : __('Expiry tracked') }}@endif</p>
                                    <p class="text-xs text-slate-600 dark:text-slate-300">{{ __('In stock here') }}: <span class="tabular-nums" data-existing-stock>{{ $r['stock'] }}</span> {{ __($p?->baseUnit?->name ?? '') }}</p>
                                    @if ($r['type'] === 'additional')
                                        <p class="mt-1"><span class="rounded-full bg-sky-100 px-2 py-0.5 text-xs font-semibold text-sky-900 dark:bg-sky-900/40 dark:text-sky-100" title="{{ $r['type_reason'] }}" data-line-type="additional">{{ __('Additional stock in') }}</span>
                                            <span class="block text-[11px] text-slate-500">{{ __('Added on top of the existing stock (not opening stock).') }}</span></p>
                                    @else
                                        <p class="mt-1"><span class="rounded-full bg-brand-100 px-2 py-0.5 text-xs font-semibold text-brand-900 dark:bg-brand-900/40 dark:text-brand-100" data-line-type="opening">{{ __('Opening stock') }}</span></p>
                                    @endif
                                </div>
                                <div>
                                    <label for="osl-unit-{{ $i }}" class="form-label xl:sr-only">{{ __('Unit') }}</label>
                                    <select id="osl-unit-{{ $i }}" wire:model.live="lines.{{ $i }}.product_unit_id" class="form-input">@foreach ($this->unitsFor($line['product_id']) as $u) <option value="{{ $u->id }}">{{ __($u->unit->name) }}{{ $u->is_base ? '' : ' ('.Decimal::trim($u->factor).')' }}</option> @endforeach</select>
                                    @error("lines.$i.product_unit_id") <p class="form-error">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="osl-qty-{{ $i }}" class="form-label xl:sr-only">{{ __('Quantity') }}</label>
                                    <input id="osl-qty-{{ $i }}" type="text" inputmode="decimal" wire:model.live.debounce.500ms="lines.{{ $i }}.quantity" class="form-input tabular-nums">
                                    @error("lines.$i.quantity") <p class="form-error">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="osl-cost-{{ $i }}" class="form-label xl:sr-only">{{ __('Cost per unit') }} *</label>
                                    <div class="relative"><span class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-[11px] font-semibold text-slate-500">{{ $currency }}</span>
                                    <input id="osl-cost-{{ $i }}" type="text" inputmode="decimal" wire:model.live.debounce.500ms="lines.{{ $i }}.unit_cost" class="form-input pl-10 tabular-nums" placeholder="{{ __('Enter cost') }}"></div>
                                    <p class="mt-0.5 text-[11px] text-slate-500">/ {{ $r['unit_name'] }}</p>
                                    @if ($r['buying'] !== null)<p class="text-[11px] text-slate-500">{{ match ($r['buying_source']) { 'average' => __('Suggested: current average cost'), 'last' => __('Suggested: last purchase cost'), 'reference' => __('Suggested: reference buying price'), default => '' } }} ({{ Decimal::format($r['buying']) }})</p>@endif
                                    @error("lines.$i.unit_cost") <p class="form-error">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="osl-price-{{ $i }}" class="form-label xl:sr-only">{{ __('New selling price (optional)') }}</label>
                                    <div class="relative"><span class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-[11px] font-semibold text-slate-500">{{ $currency }}</span>
                                    <input id="osl-price-{{ $i }}" type="text" inputmode="decimal" wire:model.blur="lines.{{ $i }}.proposed_selling_price" class="form-input pl-10 tabular-nums" placeholder="{{ __('No change') }}" @disabled(! $r['sellable'])>
                                    </div>
                                    <p class="mt-0.5 text-[11px] text-slate-500">/ {{ $r['unit_name'] }} · {{ __('Now') }}: <span class="tabular-nums">{{ $r['selling'] !== null ? Decimal::format($r['selling']) : '—' }}</span></p>
                                    @error("lines.$i.proposed_selling_price") <p class="form-error">{{ $message }}</p> @enderror
                                </div>
                                <div class="text-sm xl:pt-2.5 xl:text-right"><span class="text-xs text-slate-500 xl:hidden">{{ __('Value') }}: </span><span class="font-semibold tabular-nums">{{ $r['value'] !== null ? Decimal::format($r['value']) : '—' }}</span><span class="block text-[11px] text-slate-400">= {{ Decimal::trim(Decimal::round($r['qty_base'], Decimal::QTY)) }} {{ __($p?->baseUnit?->name ?? '') }}</span></div>
                                <div class="col-span-2 flex justify-end gap-1 xl:col-span-1">
                                    @if ($p?->requiresBatch())<button type="button" wire:click="addLot({{ $i }})" class="inline-flex min-h-10 items-center rounded-lg px-3 text-sm font-medium text-brand-700 hover:bg-brand-50 xl:hidden dark:text-brand-100 dark:hover:bg-brand-900/30">+ {{ __('Another batch') }}</button>@endif
                                    <button type="button" wire:click="removeLine({{ $i }})" class="grid size-10 place-items-center rounded-lg text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/40" aria-label="{{ __('Remove') }} {{ $p?->name }}"><x-icon name="x" /></button>
                                </div>

                                @error("lines.$i.product_id") <p class="form-error col-span-2 xl:col-span-full">{{ $message }}</p> @enderror
                                @if ($r['zero'])
                                    <label class="col-span-2 flex min-h-10 items-start gap-2 rounded-lg bg-amber-50 p-2 text-sm text-amber-900 xl:col-span-full dark:bg-amber-950/40 dark:text-amber-200">
                                        <input type="checkbox" wire:model.live="lines.{{ $i }}.zero_cost_confirmed" class="mt-0.5 size-5 rounded border-slate-300 text-amber-600">
                                        <span>{{ __('I confirm this stock has no cost (e.g. free samples). A manager allowed to approve zero cost must post it.') }}</span>
                                    </label>
                                @endif
                                @if ($p?->requiresBatch())
                                    <div class="col-span-2 grid grid-cols-2 gap-3 rounded-lg bg-slate-50 p-2 sm:grid-cols-3 xl:col-span-full xl:grid-cols-[1fr_1fr_1fr_auto] xl:items-end dark:bg-slate-800/60">
                                        <div class="col-span-2 sm:col-span-1 xl:col-span-1">
                                            <label for="osl-batch-{{ $i }}" class="form-label">{{ __('Batch number') }}{{ $p->track_batches ? ' *' : '' }}</label>
                                            <input id="osl-batch-{{ $i }}" type="text" wire:model="lines.{{ $i }}.batch_number" class="form-input uppercase" autocomplete="off" x-on:keydown.enter.prevent>
                                            @error("lines.$i.batch_number") <p class="form-error">{{ $message }}</p> @enderror
                                        </div>
                                        <div>
                                            <label for="osl-mfd-{{ $i }}" class="form-label">{{ __('Manufactured') }}</label>
                                            <input id="osl-mfd-{{ $i }}" type="date" wire:model="lines.{{ $i }}.manufactured_on" class="form-input">
                                            @error("lines.$i.manufactured_on") <p class="form-error">{{ $message }}</p> @enderror
                                        </div>
                                        <div>
                                            <label for="osl-exp-{{ $i }}" class="form-label">{{ __('Expiry date') }}{{ $p->track_expiry ? ' *' : '' }}</label>
                                            <input id="osl-exp-{{ $i }}" type="date" wire:model="lines.{{ $i }}.expires_on" class="form-input">
                                            @error("lines.$i.expires_on") <p class="form-error">{{ $message }}</p> @enderror
                                        </div>
                                        <button type="button" wire:click="addLot({{ $i }})" class="hidden min-h-10 items-center rounded-lg px-3 text-sm font-medium text-brand-700 hover:bg-brand-50 xl:inline-flex dark:text-brand-100 dark:hover:bg-brand-900/30">+ {{ __('Another batch') }}</button>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center justify-end gap-x-6 gap-y-1 border-t border-slate-200 pt-3 text-sm dark:border-slate-700" data-lines-footer>
                        <span>{{ __('Products') }}: <strong>{{ $summary['products'] }}</strong></span>
                        <span>{{ __('Total quantity') }}: <strong class="tabular-nums">{{ Decimal::trim(Decimal::round($summary['quantity'], Decimal::QTY)) }}</strong></span>
                        <span>{{ __('Opening value') }}: <strong class="tabular-nums">{{ $currency }} {{ Decimal::format($summary['value']) }}</strong></span>
                    </div>
                    <p class="text-xs text-slate-500">{{ __('Cost and selling price are prefilled and can be edited. Catalogue selling prices change only if you tick the confirmation when posting, and only where the price really changes.') }}</p>
                @endif
            </section>

            @if ($anyZero)
                <section class="card">
                    <x-ui.field :label="__('Reason for zero-cost stock').' *'" for="os-zero" error="zeroCostReason" :hint="$can['zero'] ? null : __('You can save this draft; a manager allowed to approve zero cost must post it.')">
                        <input id="os-zero" type="text" wire:model="zeroCostReason" class="form-input" maxlength="255">
                    </x-ui.field>
                </section>
            @endif

            <div class="sticky bottom-0 z-10 -mx-3 flex flex-wrap items-center gap-2 border-t border-slate-200 bg-white/95 px-3 py-2.5 pb-[max(0.625rem,env(safe-area-inset-bottom))] backdrop-blur sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8 dark:border-slate-700 dark:bg-slate-900/95" data-form-actions>
                <button type="button" wire:click="saveDraft" wire:loading.attr="disabled" wire:target="saveDraft,confirmPost,post" class="btn btn-secondary min-w-0 flex-1 sm:flex-none">{{ __('Save draft') }}</button>
                @if ($can['post'])
                    {{-- Opens the confirmation dialog after a full server-side check (no browser confirm()). --}}
                    <button type="button" wire:click="confirmPost" wire:loading.attr="disabled" wire:target="saveDraft,confirmPost,post"
                            class="btn btn-primary min-w-0 flex-1 sm:flex-none" data-post aria-haspopup="dialog">
                        <span wire:loading.remove wire:target="confirmPost">{{ __('Post opening stock') }}</span><span wire:loading wire:target="confirmPost">{{ __('Checking…') }}</span>
                    </button>
                @endif
                @if ($document)
                    <button type="button" wire:click="$set('confirmingCancel', true)" class="btn btn-secondary sm:ml-auto" aria-haspopup="dialog" data-cancel-draft>{{ __('Cancel draft') }}</button>
                @endif
            </div>
        </div>
    @else
        {{-- Read-only: posted / cancelled, or a large imported draft --}}
        <section class="card mb-5 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
            <div><p class="text-slate-500">{{ __('Opening date') }}</p><p class="font-medium">{{ $document->opening_date->format('d/m/Y') }}</p></div>
            <div><p class="text-slate-500">{{ __('Created by') }}</p><p class="font-medium">{{ $document->creator?->name ?? '—' }} · {{ $document->created_at->format('d/m/Y H:i') }}</p></div>
            <div><p class="text-slate-500">{{ __('Posted by') }}</p><p class="font-medium">{{ $document->poster ? $document->poster->name.' · '.$document->posted_at->format('d/m/Y H:i') : '—' }}</p></div>
            @if ($document->source === 'import')<div><p class="text-slate-500">{{ __('Imported from') }}</p><p class="font-medium [overflow-wrap:anywhere]">{{ $document->import_filename }}</p></div>@endif
            @if ($document->zero_cost_reason)<div><p class="text-slate-500">{{ __('Zero-cost reason') }}</p><p class="font-medium">{{ $document->zero_cost_reason }}@if ($document->zeroCostApprover) · {{ __('approved by :name', ['name' => $document->zeroCostApprover->name]) }}@endif</p></div>@endif
            @if ($document->notes)<div class="sm:col-span-2 lg:col-span-3"><p class="text-slate-500">{{ __('Notes') }}</p><p>{{ $document->notes }}</p></div>@endif
        </section>

        @if ($document->isDraft())
            <div class="mb-5 flex flex-wrap items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                <span class="flex-1">{{ __('Draft with :count lines. Check the lines below, then post. Stock has not changed yet.', ['count' => $document->line_count]) }}</span>
                @if ($can['post'])<button type="button" wire:click="confirmPost" wire:loading.attr="disabled" wire:target="confirmPost,post" class="btn btn-primary" data-post aria-haspopup="dialog">{{ __('Post opening stock') }}</button>@endif
                @if ($can['manage'])<button type="button" wire:click="$set('confirmingCancel', true)" class="btn btn-secondary" aria-haspopup="dialog" data-cancel-draft>{{ __('Cancel draft') }}</button>@endif
            </div>
        @endif

        <x-ui.table>
            <x-slot:head>
                <th class="px-4 py-3">{{ __('Product') }}</th>
                <th class="px-4 py-3">{{ __('Type') }}</th>
                <th class="px-4 py-3 text-right">{{ __('Quantity') }}</th>
                <th class="px-4 py-3 text-right">{{ __('Cost per unit') }}</th>
                <th class="px-4 py-3 text-right">{{ __('Base quantity') }}</th>
                <th class="px-4 py-3 text-right">{{ __('Cost per base unit') }}</th>
                <th class="px-4 py-3 text-right">{{ __('Value') }}</th>
                <th class="px-4 py-3">{{ __('Batch / expiry') }}</th>
            </x-slot:head>
            @foreach ($postedLines as $l)
                <tr wire:key="pl-{{ $l->id }}">
                    <td class="px-4 py-3"><span class="font-medium">{{ $l->product->name }}</span><span class="block text-xs text-slate-500">{{ $l->product->sku }}@if ($l->zero_cost_confirmed) · {{ __('zero cost') }}@endif</span></td>
                    <td class="px-4 py-3">{{ $l->line_type === 'additional' ? __('Additional stock in') : __('Opening stock') }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ Decimal::trim($l->quantity) }} {{ __($l->productUnit->unit->name) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ Decimal::format($l->unit_cost, 2) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ Decimal::trim($l->quantity_base) }} {{ __($l->product->baseUnit->name) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ Decimal::format($l->unit_cost_base, 2) }}</td>
                    <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ Decimal::format($l->line_value) }}</td>
                    <td class="px-4 py-3">{{ $l->batch?->batch_number ?? $l->batch_number ?? '—' }}@if ($l->expires_on) · {{ $l->expires_on->format('d/m/Y') }}@endif</td>
                </tr>
            @endforeach
        </x-ui.table>
        <div class="mt-4">{{ $postedLines->links() }}</div>

        <section class="card mt-5">
            <h3 class="mb-3 font-semibold">{{ __('History') }}</h3>
            <ol class="space-y-2 text-sm">
                @foreach ($events as $e)
                    <li class="flex flex-wrap gap-x-2"><span class="font-medium">{{ __(ucfirst($e->event)) }}</span><span class="text-slate-500">{{ $e->user?->name }} · {{ $e->created_at->format('d/m/Y H:i') }}</span>@if ($e->notes)<span class="text-slate-600 dark:text-slate-300">— {{ $e->notes }}</span>@endif</li>
                @endforeach
            </ol>
        </section>
    @endif
    {{-- Post confirmation: totals come from the server check (OpeningStockService::preview), not from the browser. --}}
    @if ($can['post'] && ($document === null || $document->isDraft()))
        <x-ui.confirm-dialog wire:model="confirmingPost" confirm="post" max-width="max-w-lg" return-focus="[data-post]"
            :title="__('Post opening stock?')"
            :description="__('Posting adds this stock and its value to inventory. It cannot be edited afterwards; corrections are made with Stock adjustments.')"
            :confirm-label="__('Confirm posting')" :busy-label="__('Posting…')">
            @if ($confirmation)
                <dl class="divide-y divide-slate-100 rounded-xl border border-slate-200 text-sm dark:divide-slate-700 dark:border-slate-700" data-post-summary>
                    @foreach ([
                        [__('Branch'), $confirmation['branch']],
                        [__('Stock location'), $confirmation['location'].' · '.($confirmation['sellable'] ? __('Sellable') : __('Not sellable'))],
                        [__('Opening date'), \Illuminate\Support\Carbon::parse($confirmation['opening_date'])->format('d/m/Y')],
                        [__('Products'), $confirmation['products']],
                        [__('Total quantity'), Decimal::trim(Decimal::round($confirmation['quantity'], Decimal::QTY))],
                    ] as [$label, $value])
                        <div class="flex items-baseline justify-between gap-4 px-4 py-2.5"><dt class="text-slate-500 dark:text-slate-400">{{ $label }}</dt><dd class="text-right font-medium [overflow-wrap:anywhere]">{{ $value }}</dd></div>
                    @endforeach
                    <div class="flex items-baseline justify-between gap-4 bg-brand-50/60 px-4 py-3 dark:bg-brand-900/20"><dt class="font-semibold">{{ __('Opening stock value') }}</dt><dd class="text-right text-lg font-semibold tabular-nums" data-post-value>{{ $currency }} {{ Decimal::format($confirmation['value']) }}</dd></div>
                </dl>
                @if (! $confirmation['sellable'])<p class="mt-3 text-sm text-slate-600 dark:text-slate-300">{{ __('This location is not sellable: transfer the stock to the sales floor before the POS can sell it.') }}</p>@endif
                @if ($confirmation['zero_cost_lines'] > 0)<p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">{{ __(':n lines have zero cost (approved by you).', ['n' => $confirmation['zero_cost_lines']]) }}</p>@endif
                @if (($confirmation['additional_lines'] ?? 0) > 0)
                    <p class="mt-3 rounded-lg bg-sky-50 px-3 py-2 text-sm text-sky-900 dark:bg-sky-950/40 dark:text-sky-100" data-additional-note>{{ __(':n lines are Additional stock in: they are added on top of the stock already there and recorded as "Additional stock in", not as opening stock.', ['n' => $confirmation['additional_lines']]) }}</p>
                @endif
                @if (($confirmation['price_changes'] ?? []) !== [])
                    <div class="mt-3 rounded-xl border border-slate-200 p-3 text-sm dark:border-slate-700" data-price-changes>
                        <p class="font-semibold">{{ __('Proposed new selling prices') }}</p>
                        <ul class="mt-1 space-y-0.5">
                            @foreach ($confirmation['price_changes'] as $c)
                                <li class="flex justify-between gap-3"><span class="[overflow-wrap:anywhere]">{{ $c['product'] }} <span class="text-xs text-slate-500">/ {{ $c['unit'] }}</span></span><span class="shrink-0 tabular-nums">{{ Decimal::format($c['from']) }} → <strong>{{ Decimal::format($c['to']) }}</strong></span></li>
                            @endforeach
                        </ul>
                        @if ($confirmation['can_change_prices'])
                            <label class="mt-2 flex min-h-11 items-start gap-2 rounded-lg bg-amber-50 p-2 text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                                <input type="checkbox" wire:model="applyPrices" class="mt-0.5 size-5 rounded border-slate-300 accent-amber-600" data-apply-prices>
                                <span>{{ __('Also update these catalogue selling prices (all branches, from now on). Leave unticked to post the stock only.') }}</span>
                            </label>
                        @else
                            <p class="mt-2 text-xs text-slate-500">{{ __('You cannot change selling prices: only the stock will be posted. The proposals stay on this document.') }}</p>
                        @endif
                    </div>
                @endif
            @endif
            @if ($errors->any())
                <div class="mt-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200" role="alert" data-post-error>
                    <p class="font-semibold">{{ __('Nothing was posted.') }}</p>
                    <p>{{ $errors->first() }}</p>
                </div>
            @endif
        </x-ui.confirm-dialog>
    @endif

    @if ($document?->isDraft() && $can['manage'])
        <x-ui.confirm-dialog wire:model="confirmingCancel" confirm="cancelDraft" tone="danger" max-width="max-w-md" return-focus="[data-cancel-draft]"
            :title="__('Cancel this draft?')" :description="__('The draft :ref will be cancelled. Nothing is posted and stock does not change.', ['ref' => $document->reference])"
            :confirm-label="__('Cancel draft')" :cancel-label="__('Keep draft')" :busy-label="__('Cancelling…')" />
    @endif
</div>
