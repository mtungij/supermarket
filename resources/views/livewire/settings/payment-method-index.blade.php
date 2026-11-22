<div>
    <x-ui.page-header :title="__('Payment methods')" :description="__('Methods cashiers can accept. All are confirmed manually by staff; no payment provider is connected.')">
        <x-slot:actions><button type="button" wire:click="create" class="btn btn-primary"><x-icon name="plus" /> {{ __('Add method') }}</button></x-slot:actions>
    </x-ui.page-header>
    @include('components.ui.flash')
    @error('is_active') <div class="mb-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200">{{ $message }}</div> @enderror
    <x-ui.table>
        <x-slot:head><th class="px-4 py-3">{{ __('Name') }}</th><th class="px-4 py-3">{{ __('Type') }}</th><th class="px-4 py-3">{{ __('Reference') }}</th><th class="px-4 py-3">{{ __('Status') }}</th><th class="px-4 py-3"></th></x-slot:head>
        @foreach ($methods as $m)
            <tr wire:key="pm-{{ $m->uuid }}">
                <td class="px-4 py-3 font-medium">{{ __($m->name) }}</td><td class="px-4 py-3">{{ __($m->type->label()) }}</td>
                <td class="px-4 py-3">{{ $m->requires_reference ? __('Required') : __('Optional') }}</td>
                <td class="px-4 py-3"><x-ui.status :status="$m->is_active ? 'active' : 'inactive'" /></td>
                <td class="px-4 py-3 text-right">@if ($m->type->value !== 'exchange_credit')<button type="button" wire:click="edit('{{ $m->uuid }}')" class="rounded-lg px-3 py-2 text-sm font-medium hover:bg-slate-100 dark:hover:bg-slate-700">{{ __('Edit') }}</button>@endif</td>
            </tr>
        @endforeach
    </x-ui.table>
    <x-ui.modal wire:model="showForm" :title="$editing ? __('Edit payment method') : __('Add method')">
        <div class="grid gap-5 sm:grid-cols-2">
            <x-ui.field class="sm:col-span-2" :label="__('Name')" for="pm-name" error="name"><input id="pm-name" type="text" wire:model="name" class="form-input" placeholder="{{ __('e.g. M-Pesa') }}"></x-ui.field>
            <x-ui.field :label="__('Type')" for="pm-type" error="type"><select id="pm-type" wire:model="type" class="form-input" @disabled($editing)>@foreach ($types as $t) <option value="{{ $t->value }}">{{ __($t->label()) }}</option> @endforeach</select></x-ui.field>
            <x-ui.field :label="__('Sort order')" for="pm-sort" error="sortOrder"><input id="pm-sort" type="number" wire:model="sortOrder" class="form-input"></x-ui.field>
            <label class="flex min-h-11 items-center gap-3 text-sm"><input type="checkbox" wire:model="requiresReference" class="size-5 rounded"> {{ __('Reference required') }}</label>
            <label class="flex min-h-11 items-center gap-3 text-sm"><input type="checkbox" wire:model="isActive" class="size-5 rounded"> {{ __('Active') }}</label>
            <div class="flex gap-3 sm:col-span-2"><button type="button" wire:click="save" class="btn btn-primary">{{ __('Save') }}</button><button type="button" wire:click="$set('showForm', false)" class="btn btn-secondary">{{ __('Cancel') }}</button></div>
        </div>
    </x-ui.modal>
</div>
