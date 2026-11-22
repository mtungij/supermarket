@use('App\Support\Decimal')
<div>
    <x-ui.page-header :title="__('Customers')" :description="__('Registered customers. Walk-in sales need no customer.')">
        @if ($canManage)
            <x-slot:actions><button type="button" wire:click="create" class="btn btn-primary"><x-icon name="plus" /> {{ __('Add customer') }}</button></x-slot:actions>
        @endif
    </x-ui.page-header>
    @include('components.ui.flash')
    <div class="mb-4"><x-ui.search wire:model.live.debounce.300ms="search" :placeholder="__('Name, phone or email')" /></div>
    <x-ui.table>
        <x-slot:head><th class="px-4 py-3">{{ __('Name') }}</th><th class="px-4 py-3">{{ __('Phone') }}</th><th class="px-4 py-3">{{ __('Email') }}</th><th class="px-4 py-3 text-right">{{ __('Sales') }}</th><th class="px-4 py-3 text-right">{{ __('Total') }}</th><th class="px-4 py-3">{{ __('Status') }}</th><th class="px-4 py-3"></th></x-slot:head>
        @forelse ($customers as $c)
            <tr wire:key="cust-{{ $c->uuid }}">
                <td class="px-4 py-3 font-medium">{{ $c->name }}</td><td class="px-4 py-3">{{ $c->phone }}</td><td class="px-4 py-3">{{ $c->email }}</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ $c->sales_count }}</td><td class="px-4 py-3 text-right tabular-nums">{{ Decimal::format((string) ($c->sales_total ?? '0')) }}</td>
                <td class="px-4 py-3"><x-ui.status :status="$c->is_active ? 'active' : 'inactive'" /></td>
                <td class="px-4 py-3 text-right">@if ($canManage)<button type="button" wire:click="edit('{{ $c->uuid }}')" class="inline-flex min-h-10 items-center rounded-lg px-3 py-2 text-sm font-medium hover:bg-slate-100 dark:hover:bg-slate-700">{{ __('Edit') }}</button>@endif</td>
            </tr>
        @empty
            <x-ui.empty-row colspan="7">{{ __('No customers yet.') }}</x-ui.empty-row>
        @endforelse
    </x-ui.table>
    <div class="mt-4">{{ $customers->links() }}</div>
    <x-ui.modal wire:model="showForm" :title="$editing ? __('Edit customer') : __('Add customer')">
        <div class="grid gap-5 sm:grid-cols-2">
            <x-ui.field class="sm:col-span-2" :label="__('Name')" for="c-name" error="form.name"><input id="c-name" type="text" wire:model="form.name" class="form-input" required></x-ui.field>
            <x-ui.field :label="__('Phone')" for="c-phone" error="form.phone"><input id="c-phone" type="tel" wire:model="form.phone" class="form-input"></x-ui.field>
            <x-ui.field :label="__('Email')" for="c-email" error="form.email"><input id="c-email" type="email" wire:model="form.email" class="form-input"></x-ui.field>
            <x-ui.field class="sm:col-span-2" :label="__('Notes')" for="c-notes" error="form.notes"><textarea id="c-notes" wire:model="form.notes" rows="2" class="form-input"></textarea></x-ui.field>
            <label class="flex min-h-11 items-center gap-3 text-sm"><input type="checkbox" wire:model="form.is_active" class="size-5 rounded"> {{ __('Active') }}</label>
            <div class="flex gap-3 sm:col-span-2"><button type="button" wire:click="save" class="btn btn-primary">{{ __('Save') }}</button><button type="button" wire:click="$set('showForm', false)" class="btn btn-secondary">{{ __('Cancel') }}</button></div>
        </div>
    </x-ui.modal>
</div>
