<div>
    <x-ui.page-header :title="__('Suppliers')" :description="__('Suppliers you buy from. Balances come from posted goods receipts.')">
        @if ($canManage)
            <x-slot:actions><button type="button" wire:click="create" class="btn btn-primary"><x-icon name="plus" /> {{ __('Add supplier') }}</button></x-slot:actions>
        @endif
    </x-ui.page-header>
    @include('components.ui.flash')

    <div class="mb-4 flex flex-col gap-3 sm:flex-row">
        <x-ui.search wire:model.live.debounce.300ms="search" :placeholder="__('Name, phone or contact')" />
        <select wire:model.live="status" class="form-input sm:w-48" aria-label="{{ __('Status') }}">
            <option value="active">{{ __('Active') }}</option><option value="inactive">{{ __('Inactive') }}</option><option value="all">{{ __('All') }}</option>
        </select>
    </div>

    <x-ui.table>
        <x-slot:head><th class="px-4 py-3">{{ __('Supplier') }}</th><th class="px-4 py-3">{{ __('Contact') }}</th><th class="px-4 py-3">{{ __('Orders') }}</th><th class="px-4 py-3 text-right">{{ __('Outstanding') }}</th><th class="px-4 py-3">{{ __('Status') }}</th><th class="px-4 py-3"></th></x-slot:head>
        @forelse ($suppliers as $s)
            <tr wire:key="sup-{{ $s->uuid }}">
                <td class="px-4 py-3"><a href="{{ route('purchasing.suppliers.show', $s) }}" class="font-medium text-brand-700 hover:underline dark:text-brand-100">{{ $s->name }}</a><p class="text-xs text-slate-500">{{ $s->tax_id ? __('TIN').' '.$s->tax_id : '' }}</p></td>
                <td class="px-4 py-3 text-sm">{{ $s->contact_person ?? '—' }}<p class="text-xs text-slate-500">{{ $s->phone }} {{ $s->email }}</p></td>
                <td class="px-4 py-3 tabular-nums">{{ $s->purchase_orders_count }}</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ \App\Support\Decimal::format((string) ($s->balance ?? '0')) }}</td>
                <td class="px-4 py-3"><x-ui.status :status="$s->is_active ? 'active' : 'inactive'" /></td>
                <td class="px-4 py-3 text-right">@if ($canManage)<button type="button" wire:click="edit('{{ $s->uuid }}')" class="rounded-lg px-3 py-2 text-sm font-medium hover:bg-slate-100 dark:hover:bg-slate-700">{{ __('Edit') }}</button>@endif</td>
            </tr>
        @empty
            <x-ui.empty-row colspan="6">{{ __('No suppliers yet.') }}</x-ui.empty-row>
        @endforelse
    </x-ui.table>
    <div class="mt-4">{{ $suppliers->links() }}</div>

    <x-ui.modal wire:model="showForm" :title="$editing ? __('Edit supplier') : __('Add supplier')">
        <form wire:submit="save" class="grid gap-5 sm:grid-cols-2">
            <x-ui.field class="sm:col-span-2" :label="__('Supplier name')" for="s-name" error="form.name"><input id="s-name" type="text" wire:model="form.name" class="form-input" required></x-ui.field>
            <x-ui.field :label="__('Phone')" for="s-phone" error="form.phone"><input id="s-phone" type="tel" wire:model="form.phone" class="form-input"></x-ui.field>
            <x-ui.field :label="__('Email')" for="s-email" error="form.email"><input id="s-email" type="email" wire:model="form.email" class="form-input"></x-ui.field>
            <x-ui.field :label="__('Contact person')" for="s-contact" error="form.contact_person"><input id="s-contact" type="text" wire:model="form.contact_person" class="form-input"></x-ui.field>
            <x-ui.field :label="__('Tax identifier (TIN)')" for="s-tin" error="form.tax_id"><input id="s-tin" type="text" wire:model="form.tax_id" class="form-input"></x-ui.field>
            <x-ui.field class="sm:col-span-2" :label="__('Address')" for="s-address" error="form.address"><textarea id="s-address" wire:model="form.address" rows="2" class="form-input"></textarea></x-ui.field>
            <x-ui.field class="sm:col-span-2" :label="__('Notes')" for="s-notes" error="form.notes"><textarea id="s-notes" wire:model="form.notes" rows="2" class="form-input"></textarea></x-ui.field>
            <label class="flex min-h-11 items-center gap-3 text-sm"><input type="checkbox" wire:model="form.is_active" class="size-5 rounded border-slate-300 text-brand-600"> {{ __('Active') }}</label>
            <div class="flex gap-3 sm:col-span-2"><button type="submit" class="btn btn-primary" wire:loading.attr="disabled">{{ __('Save') }}</button><button type="button" class="btn btn-secondary" wire:click="$set('showForm', false)">{{ __('Cancel') }}</button></div>
        </form>
    </x-ui.modal>
</div>
