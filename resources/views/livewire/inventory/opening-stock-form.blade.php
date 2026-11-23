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

            <section class="card space-y-4" aria-label="{{ __('Products') }}">
                @include('livewire.partials.product-picker')
                @error('lines') <p class="form-error">{{ $message }}</p> @enderror

                <div class="space-y-3">
                    @forelse ($lines as $i => $line)
                        @php($p = $products[$line['product_id']] ?? null)
                        <div wire:key="osl-{{ $i }}" class="rounded-xl border border-slate-200 p-3 sm:p-4 dark:border-slate-700 @error("lines.$i.product_id") border-red-400 dark:border-red-700 @enderror" data-opening-line>
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="font-semibold [overflow-wrap:anywhere]">{{ $p?->name }}</p>
                                    <p class="text-xs text-slate-500">{{ $p?->sku }}@if ($p?->requiresBatch()) · {{ $p->track_batches ? __('Batch tracked') : __('Expiry tracked') }}@endif</p>
                                </div>
                                <p class="text-right text-sm"><span class="text-slate-500">{{ __('Value') }}</span><br><span class="font-semibold tabular-nums">{{ $rows[$i]['value'] !== null ? Decimal::format($rows[$i]['value']) : '—' }}</span></p>
                            </div>
                            @error("lines.$i.product_id") <p class="form-error">{{ $message }}</p> @enderror
                            <div class="mt-3 grid grid-cols-2 gap-3 md:grid-cols-4">
                                <div>
                                    <label for="osl-unit-{{ $i }}" class="form-label">{{ __('Unit') }}</label>
                                    <select id="osl-unit-{{ $i }}" wire:model.live="lines.{{ $i }}.product_unit_id" class="form-input">@foreach ($this->unitsFor($line['product_id']) as $u) <option value="{{ $u->id }}">{{ __($u->unit->name) }}{{ $u->is_base ? '' : ' ('.Decimal::trim($u->factor).')' }}</option> @endforeach</select>
                                    @error("lines.$i.product_unit_id") <p class="form-error">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="osl-qty-{{ $i }}" class="form-label">{{ __('Quantity') }}</label>
                                    <input id="osl-qty-{{ $i }}" type="text" inputmode="decimal" wire:model.live.debounce.500ms="lines.{{ $i }}.quantity" class="form-input tabular-nums">
                                    @error("lines.$i.quantity") <p class="form-error">{{ $message }}</p> @enderror
                                </div>
                                <div class="col-span-2 md:col-span-2">
                                    <label for="osl-cost-{{ $i }}" class="form-label">{{ __('Cost per unit') }} *</label>
                                    <input id="osl-cost-{{ $i }}" type="text" inputmode="decimal" wire:model.live.debounce.500ms="lines.{{ $i }}.unit_cost" class="form-input tabular-nums" placeholder="{{ __('Enter cost') }}">
                                    @if ($p?->reference_buying_price !== null && $line['unit_cost'] === '')
                                        <p class="mt-1 text-xs text-slate-500">{{ __('Reference buying price: :price per :unit', ['price' => Decimal::format($p->reference_buying_price), 'unit' => __($p->baseUnit->name)]) }}
                                            <button type="button" wire:click="useReferenceCost({{ $i }})" class="ml-1 inline-flex min-h-10 items-center font-semibold text-brand-700 underline dark:text-brand-100">{{ __('Use it') }}</button></p>
                                    @endif
                                    @error("lines.$i.unit_cost") <p class="form-error">{{ $message }}</p> @enderror
                                    @if ($rows[$i]['zero'])
                                        <label class="mt-2 flex min-h-10 items-start gap-2 rounded-lg bg-amber-50 p-2 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                                            <input type="checkbox" wire:model.live="lines.{{ $i }}.zero_cost_confirmed" class="mt-0.5 size-5 rounded border-slate-300 text-amber-600">
                                            <span>{{ __('I confirm this stock has no cost (e.g. free samples). A manager allowed to approve zero cost must post it.') }}</span>
                                        </label>
                                    @endif
                                </div>
                                @if ($p?->requiresBatch())
                                    <div class="col-span-2 md:col-span-1">
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
                                @endif
                            </div>
                            <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500">
                                <span>= {{ Decimal::trim(Decimal::round($rows[$i]['qty_base'], Decimal::QTY)) }} {{ __($p?->baseUnit?->name ?? '') }}</span>
                                <span class="flex gap-1">
                                    @if ($p?->requiresBatch())<button type="button" wire:click="addLot({{ $i }})" class="inline-flex min-h-10 items-center rounded-lg px-3 text-sm font-medium text-brand-700 hover:bg-brand-50 dark:text-brand-100 dark:hover:bg-brand-900/30">+ {{ __('Another batch') }}</button>@endif
                                    <button type="button" wire:click="removeLine({{ $i }})" class="inline-flex min-h-10 items-center rounded-lg px-3 text-sm font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/40">{{ __('Remove') }}</button>
                                </span>
                            </div>
                        </div>
                    @empty
                        <div class="rounded-xl border border-dashed border-slate-300 px-4 py-10 text-center text-slate-500 dark:border-slate-600">{{ __('Scan a barcode or search a product to add your existing stock.') }}</div>
                    @endforelse
                </div>
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
