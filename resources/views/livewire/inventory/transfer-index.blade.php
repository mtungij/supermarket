@use('App\Support\Decimal')
<div>
    <x-ui.page-header :title="__('Stock transfers')" :description="__('Between locations or branches. Branch-to-branch goods are in transit until the destination receives them.')">
        @if ($can['transfer'])
            <x-slot:actions><button type="button" wire:click="create" class="btn btn-primary"><x-icon name="plus" /> {{ __('New transfer') }}</button></x-slot:actions>
        @endif
    </x-ui.page-header>
    @include('components.ui.flash')
    @foreach (['status', 'quantity', 'batch', 'document'] as $f) @error($f) <div class="mb-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200" role="alert">{{ $message }}</div> @enderror @endforeach

    <div class="mb-4 sm:w-64"><select wire:model.live="status" class="form-input" aria-label="{{ __('Status') }}"><option value="">{{ __('All statuses') }}</option>@foreach ($statuses as $s) <option value="{{ $s->value }}">{{ __($s->label()) }}</option> @endforeach</select></div>

    <div class="space-y-4">
        @forelse ($transfers as $t)
            @php($outgoing = $t->source_branch_id === $branch->id)
            <div class="card" wire:key="trf-{{ $t->uuid }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="font-semibold">{{ $t->reference }} <x-ui.badge :color="$outgoing ? 'blue' : 'green'">{{ $outgoing ? __('Outgoing') : __('Incoming') }}</x-ui.badge></p>
                        <p class="text-sm text-slate-500">{{ $t->sourceBranch->name }} / {{ $t->sourceLocation->name }} → {{ $t->destinationBranch->name }} / {{ $t->destinationLocation->name }}</p>
                    </div>
                    <x-ui.status :status="$t->status" />
                </div>
                <ul class="mt-3 divide-y divide-slate-100 text-sm dark:divide-slate-700">
                    @foreach ($t->lines as $l)
                        <li class="flex justify-between py-2"><span>{{ $l->product->name }}</span><span class="tabular-nums">{{ Decimal::trim($l->quantity_base) }} {{ __($l->product->baseUnit->name) }}</span></li>
                    @endforeach
                </ul>
                <div class="mt-3 flex flex-wrap gap-2">
                    @if ($t->status->value === 'draft' && $outgoing && $can['transfer'])
                        <button type="button" wire:click="act('dispatch', '{{ $t->uuid }}')" wire:confirm="{{ __('Dispatch this transfer? Stock leaves the source location.') }}" class="btn btn-primary">{{ __('Dispatch') }}</button>
                        <button type="button" wire:click="act('cancel', '{{ $t->uuid }}')" wire:confirm="{{ __('Cancel this transfer?') }}" class="btn btn-secondary">{{ __('Cancel') }}</button>
                    @endif
                    @if ($t->status->value === 'in_transit' && ! $outgoing && $can['receive'])
                        <button type="button" wire:click="act('receive', '{{ $t->uuid }}')" wire:confirm="{{ __('Confirm the goods have arrived?') }}" class="btn btn-primary">{{ __('Receive') }}</button>
                    @endif
                </div>
            </div>
        @empty
            <div class="card text-center text-slate-500">{{ __('No transfers yet.') }}</div>
        @endforelse
    </div>
    <div class="mt-4">{{ $transfers->links() }}</div>

    <x-ui.modal wire:model="showForm" :title="__('New transfer')" max-width="max-w-4xl">
        {{-- No <form> wrapper: the scan input below must own its Enter key. --}}
        <div class="space-y-5">
            <div class="grid gap-5 md:grid-cols-2">
                <x-ui.field :label="__('From (this branch)')" for="trf-src" error="source">
                    <select id="trf-src" wire:model.live="source" class="form-input"><option value="">{{ __('Select…') }}</option>@foreach ($sourceLocations as $l) <option value="{{ $l->uuid }}">{{ __($l->name) }}</option> @endforeach</select>
                </x-ui.field>
                <x-ui.field :label="__('To')" for="trf-dst" error="destination">
                    <select id="trf-dst" wire:model="destination" class="form-input"><option value="">{{ __('Select…') }}</option>@foreach ($destinationLocations as $l) <option value="{{ $l->uuid }}">{{ $l->branch->name }} / {{ __($l->name) }}</option> @endforeach</select>
                    @error('destination_location_id') <p class="form-error">{{ $message }}</p> @enderror
                </x-ui.field>
                <x-ui.field class="md:col-span-2" :label="__('Notes')" for="trf-notes" error="notes"><textarea id="trf-notes" wire:model="notes" rows="2" class="form-input"></textarea></x-ui.field>
            </div>

            @include('livewire.partials.product-picker')
            @error('lines') <p class="form-error">{{ $message }}</p> @enderror

            <div class="space-y-3">
                @foreach ($lines as $i => $line)
                    @php($p = $products[$line['product_id']] ?? null)
                    <div wire:key="trfl-{{ $line['product_id'] }}-{{ $line['product_unit_id'] }}-{{ $i }}" class="grid grid-cols-2 gap-3 rounded-xl border border-slate-200 p-3 md:grid-cols-6 md:items-end dark:border-slate-700">
                        <div class="col-span-2">
                            <p class="font-medium">{{ $p?->name }}</p>
                            @if ($sourceLocation && $p)
                                <p class="text-xs text-slate-500">{{ __('Available in :location: :qty :unit', ['location' => __($sourceLocation->name), 'qty' => \App\Support\Decimal::trim($this->available($p->id, $sourceLocation->id, $line['batch_id'] ? (int) $line['batch_id'] : null)), 'unit' => __($p->baseUnit->name)]) }}</p>
                            @endif
                        </div>
                        <select wire:model="lines.{{ $i }}.product_unit_id" class="form-input" aria-label="{{ __('Unit') }}">@foreach ($this->unitsFor($line['product_id']) as $u) <option value="{{ $u->id }}">{{ __($u->unit->name) }}{{ $u->is_base ? '' : ' ×'.\App\Support\Decimal::trim($u->factor) }}</option> @endforeach</select>
                        <div><input type="text" inputmode="decimal" wire:model="lines.{{ $i }}.quantity" class="form-input" aria-label="{{ __('Quantity') }}">@error("lines.$i.quantity") <p class="form-error">{{ $message }}</p> @enderror</div>
                        @if ($p?->requiresBatch())
                            <div class="col-span-2 md:col-span-1"><select wire:model.live="lines.{{ $i }}.batch_id" class="form-input" aria-label="{{ __('Batch') }}"><option value="">{{ __('Batch…') }}</option>@foreach ($this->batchesFor($line['product_id']) as $b) <option value="{{ $b->id }}">{{ $b->batch_number }}</option> @endforeach</select>@error("lines.$i.batch_id") <p class="form-error">{{ $message }}</p> @enderror</div>
                        @endif
                        <button type="button" wire:click="removeLine({{ $i }})" class="min-h-11 text-sm font-medium text-red-600 md:text-right">{{ __('Remove') }}</button>
                    </div>
                @endforeach
                @if ($lines === [])
                    <p class="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500 dark:border-slate-600">{{ __('Select the source location, then scan or search to add products.') }}</p>
                @endif
            </div>

            {{-- Saving creates a DRAFT; dispatch is always a separate, explicit action. --}}
            <div class="flex flex-col gap-3 sm:flex-row">
                <button type="button" wire:click="save(false)" wire:loading.attr="disabled" wire:target="save" class="btn btn-secondary">{{ __('Save draft') }}</button>
                <button type="button" wire:click="save(true)" wire:confirm="{{ __('Dispatch now? Stock leaves the source location.') }}" wire:loading.attr="disabled" wire:target="save" class="btn btn-primary">{{ __('Save & dispatch') }}</button>
                <button type="button" class="btn btn-secondary" wire:click="$set('showForm', false)">{{ __('Cancel') }}</button>
                <span wire:loading wire:target="save" class="self-center text-sm text-slate-500">{{ __('Saving…') }}</span>
            </div>
        </div>
    </x-ui.modal>
</div>
