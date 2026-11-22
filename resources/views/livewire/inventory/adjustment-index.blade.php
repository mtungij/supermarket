@use('App\Support\Decimal')
<div>
    <x-ui.page-header :title="__('Stock adjustments')" :description="__(':branch · every adjustment needs a reason and is approved before stock changes.', ['branch' => $branch->name])">
        @if ($can['request'])
            <x-slot:actions><button type="button" wire:click="create" class="btn btn-primary"><x-icon name="plus" /> {{ __('New adjustment') }}</button></x-slot:actions>
        @endif
    </x-ui.page-header>
    @include('components.ui.flash')
    @foreach (['status', 'quantity', 'batch', 'document'] as $f) @error($f) <div class="mb-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200" role="alert">{{ $message }}</div> @enderror @endforeach

    <div class="mb-4 sm:w-64"><select wire:model.live="status" class="form-input" aria-label="{{ __('Status') }}"><option value="">{{ __('All statuses') }}</option>@foreach ($statuses as $s) <option value="{{ $s->value }}">{{ __($s->label()) }}</option> @endforeach</select></div>

    <div class="space-y-4">
        @forelse ($adjustments as $a)
            <div class="card" wire:key="adj-{{ $a->uuid }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="font-semibold">{{ $a->reference }} · {{ __($a->reason->label()) }}</p>
                        <p class="text-sm text-slate-500">{{ $a->location->name }} · {{ __('Requested by :name', ['name' => $a->requester?->name ?? '—']) }}@if ($a->approver) · {{ __('Approved by :name', ['name' => $a->approver->name]) }}@endif</p>
                        <p class="mt-1 text-sm">{{ $a->notes }}</p>
                        @if ($a->rejection_reason)<p class="mt-1 text-sm text-red-600">{{ $a->rejection_reason }}</p>@endif
                    </div>
                    <x-ui.status :status="$a->status" />
                </div>
                <ul class="mt-3 divide-y divide-slate-100 text-sm dark:divide-slate-700">
                    @foreach ($a->lines as $l)
                        <li class="flex justify-between py-2"><span>{{ $l->product->name }}@if ($l->batch) <span class="text-xs text-slate-500">({{ $l->batch->batch_number }})</span>@endif</span><span class="tabular-nums {{ $l->direction->value === 'out' ? 'text-red-600' : 'text-brand-700' }}">{{ $l->direction->value === 'out' ? '−' : '+' }}{{ Decimal::trim($l->quantity_base) }} {{ __($l->product->baseUnit->name) }}</span></li>
                    @endforeach
                </ul>
                @if ($a->status->value === 'pending_approval' && $can['approve'])
                    <div class="mt-4 flex flex-col gap-3 border-t border-slate-200 pt-4 md:flex-row md:items-end dark:border-slate-700">
                        <button type="button" wire:click="approve('{{ $a->uuid }}')" wire:confirm="{{ __('Approve and post this adjustment? Stock will change.') }}" class="btn btn-primary">{{ __('Approve & post') }}</button>
                        <x-ui.field class="flex-1" :label="__('Rejection reason')" for="rej-{{ $a->id }}" error="rejection"><input id="rej-{{ $a->id }}" type="text" wire:model="rejection" class="form-input"></x-ui.field>
                        <button type="button" wire:click="reject('{{ $a->uuid }}')" class="btn btn-danger">{{ __('Reject') }}</button>
                    </div>
                @endif
            </div>
        @empty
            <div class="card text-center text-slate-500">{{ __('No adjustments yet.') }}</div>
        @endforelse
    </div>
    <div class="mt-4">{{ $adjustments->links() }}</div>

    <x-ui.modal wire:model="showForm" :title="__('New adjustment')" max-width="max-w-4xl">
        {{-- No <form> wrapper: the scan input below must own its Enter key. --}}
        <div class="space-y-5">
            <div class="grid gap-5 md:grid-cols-2">
                <x-ui.field :label="__('Location')" for="adj-loc" error="location"><select id="adj-loc" wire:model="location" class="form-input"><option value="">{{ __('Select…') }}</option>@foreach ($locations as $l) <option value="{{ $l->uuid }}">{{ $l->name }}</option> @endforeach</select>@error('location_id') <p class="form-error">{{ $message }}</p> @enderror</x-ui.field>
                <x-ui.field :label="__('Reason')" for="adj-reason" error="reason"><select id="adj-reason" wire:model.live="reason" class="form-input">@foreach ($reasons as $r) <option value="{{ $r->value }}">{{ __($r->label()) }}</option> @endforeach</select></x-ui.field>
                <x-ui.field class="md:col-span-2" :label="__('Explanation')" for="adj-notes" error="notes"><textarea id="adj-notes" wire:model="notes" rows="2" class="form-input" required></textarea></x-ui.field>
            </div>
            @include('livewire.partials.product-picker')
            @error('lines') <p class="form-error">{{ $message }}</p> @enderror
            <div class="space-y-3">
                @foreach ($lines as $i => $line)
                    @php($p = $products[$line['product_id']] ?? null)
                    <div wire:key="adjl-{{ $i }}" class="grid gap-3 rounded-xl border border-slate-200 p-3 md:grid-cols-6 md:items-end dark:border-slate-700">
                        <p class="font-medium md:col-span-2">{{ $p?->name }}</p>
                        <select wire:model="lines.{{ $i }}.product_unit_id" class="form-input" aria-label="{{ __('Unit') }}">@foreach ($this->unitsFor($line['product_id']) as $u) <option value="{{ $u->id }}">{{ __($u->unit->name) }}</option> @endforeach</select>
                        <div><input type="text" inputmode="decimal" wire:model="lines.{{ $i }}.quantity" class="form-input" aria-label="{{ __('Quantity') }}">@error("lines.$i.quantity") <p class="form-error">{{ $message }}</p> @enderror</div>
                        @if ($reason === 'count_correction')
                            <select wire:model="lines.{{ $i }}.direction" class="form-input" aria-label="{{ __('Direction') }}"><option value="">{{ __('Select…') }}</option><option value="in">{{ __('Increase') }}</option><option value="out">{{ __('Decrease') }}</option></select>
                        @endif
                        @if ($p?->requiresBatch())
                            <div><select wire:model="lines.{{ $i }}.batch_id" class="form-input" aria-label="{{ __('Batch') }}"><option value="">{{ __('Batch…') }}</option>@foreach ($this->batchesFor($line['product_id']) as $b) <option value="{{ $b->id }}">{{ $b->batch_number }}{{ $b->expires_on ? ' · '.$b->expires_on->format('d/m/Y') : '' }}</option> @endforeach</select>@error("lines.$i.batch_id") <p class="form-error">{{ $message }}</p> @enderror</div>
                        @endif
                        @error("lines.$i.direction") <p class="form-error md:col-span-6">{{ $message }}</p> @enderror
                        <button type="button" wire:click="removeLine({{ $i }})" class="min-h-11 text-sm font-medium text-red-600 md:text-right">{{ __('Remove') }}</button>
                    </div>
                @endforeach
            </div>
            <div class="flex flex-col gap-3 sm:flex-row"><button type="button" wire:click="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="submit">{{ __('Submit for approval') }}</button><button type="button" class="btn btn-secondary" wire:click="$set('showForm', false)">{{ __('Cancel') }}</button></div>
        </div>
    </x-ui.modal>
</div>
