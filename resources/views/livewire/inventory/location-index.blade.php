<div>
    <x-ui.page-header :title="__('Stock locations')" :description="__(':branch · Main Store, Sales Floor, Receiving Area and any other authorised storage.', ['branch' => $branch->name])">
        @if ($canManage)
            <x-slot:actions><button type="button" wire:click="$set('showForm', true)" class="btn btn-primary"><x-icon name="plus" /> {{ __('Add location') }}</button></x-slot:actions>
        @endif
    </x-ui.page-header>
    @include('components.ui.flash')
    @error('location') <div class="mb-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200" role="alert">{{ $message }}</div> @enderror

    <x-ui.table>
        <x-slot:head><th class="px-4 py-3">{{ __('Name') }}</th><th class="px-4 py-3">{{ __('Code') }}</th><th class="px-4 py-3">{{ __('Type') }}</th><th class="px-4 py-3">{{ __('Products in stock') }}</th><th class="px-4 py-3">{{ __('POS sellable') }}</th><th class="px-4 py-3">{{ __('Status') }}</th><th class="px-4 py-3"></th></x-slot:head>
        @foreach ($locations as $l)
            <tr wire:key="loc-{{ $l->uuid }}">
                <td class="px-4 py-3 font-medium">{{ __($l->name) }}</td><td class="px-4 py-3 font-mono text-xs">{{ $l->code }}</td><td class="px-4 py-3">{{ __($l->type->label()) }}</td>
                <td class="px-4 py-3 tabular-nums">{{ $counts[$l->id] ?? 0 }}</td>
                <td class="px-4 py-3">
                    @if ($canManage && $l->type->canBeSellable())
                        <button type="button" wire:click="toggleSellable('{{ $l->uuid }}')" @if (! $l->is_sellable) wire:confirm="{{ __('Allow the POS to sell stock from :location?', ['location' => __($l->name)]) }}" @endif class="inline-flex min-h-11 items-center rounded-lg px-2 hover:bg-slate-100 dark:hover:bg-slate-700">
                            <x-ui.badge :color="$l->is_sellable ? 'green' : 'slate'">{{ $l->is_sellable ? __('Sellable') : __('Not sellable') }}</x-ui.badge>
                        </button>
                    @else
                        <x-ui.badge :color="$l->is_sellable ? 'green' : 'slate'">{{ $l->is_sellable ? __('Sellable') : __('Not sellable') }}</x-ui.badge>
                    @endif
                </td>
                <td class="px-4 py-3"><x-ui.status :status="$l->is_active ? 'active' : 'inactive'" /></td>
                <td class="px-4 py-3 text-right">@if ($canManage)<button type="button" wire:click="toggle('{{ $l->uuid }}')" class="inline-flex min-h-10 items-center rounded-lg px-3 py-2 text-sm font-medium hover:bg-slate-100 dark:hover:bg-slate-700">{{ $l->is_active ? __('Deactivate') : __('Activate') }}</button>@endif</td>
            </tr>
        @endforeach
    </x-ui.table>

    <x-ui.modal wire:model="showForm" :title="__('Add location')">
        <form wire:submit="save" class="grid gap-5 sm:grid-cols-2">
            <x-ui.field class="sm:col-span-2" :label="__('Name')" for="loc-name" error="name"><input id="loc-name" type="text" wire:model="name" class="form-input" placeholder="{{ __('e.g. Cold Room') }}" required></x-ui.field>
            <x-ui.field :label="__('Code')" for="loc-code" error="code"><input id="loc-code" type="text" wire:model="code" class="form-input uppercase" placeholder="COLD" required></x-ui.field>
            <x-ui.field :label="__('Type')" for="loc-type" error="type"><select id="loc-type" wire:model="type" class="form-input">@foreach ($types as $t) <option value="{{ $t->value }}">{{ __($t->label()) }}</option> @endforeach</select></x-ui.field>
            <div class="flex gap-3 sm:col-span-2"><button type="submit" class="btn btn-primary">{{ __('Save') }}</button><button type="button" class="btn btn-secondary" wire:click="$set('showForm', false)">{{ __('Cancel') }}</button></div>
        </form>
    </x-ui.modal>
</div>
