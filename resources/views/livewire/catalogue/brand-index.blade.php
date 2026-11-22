<div>
    <x-ui.page-header :title="__('Brands')" :description="__('Brand names are unique within your company.')">
        @if ($canManage)
            <x-slot:actions><button type="button" wire:click="create" class="btn btn-primary"><x-icon name="plus" /> {{ __('Add brand') }}</button></x-slot:actions>
        @endif
    </x-ui.page-header>
    @include('components.ui.flash')

    <div class="mb-4 flex flex-col gap-3 sm:flex-row">
        <x-ui.search wire:model.live.debounce.300ms="search" />
        <select wire:model.live="status" class="form-input sm:w-48" aria-label="{{ __('Status') }}">
            <option value="active">{{ __('Active') }}</option><option value="inactive">{{ __('Inactive') }}</option><option value="all">{{ __('All') }}</option>
        </select>
    </div>

    <x-ui.table>
        <x-slot:head>
            <th class="px-4 py-3">{{ __('Name') }}</th><th class="px-4 py-3">{{ __('Products') }}</th><th class="px-4 py-3">{{ __('Status') }}</th><th class="px-4 py-3"><span class="sr-only">{{ __('Actions') }}</span></th>
        </x-slot:head>
        @forelse ($brands as $brand)
            <tr wire:key="brand-{{ $brand->uuid }}">
                <td class="px-4 py-3"><p class="font-medium">{{ $brand->name }}</p><p class="text-xs text-slate-500">{{ $brand->description }}</p></td>
                <td class="px-4 py-3 tabular-nums">{{ $brand->products_count }}</td>
                <td class="px-4 py-3"><x-ui.status :status="$brand->is_active ? 'active' : 'inactive'" /></td>
                <td class="whitespace-nowrap px-4 py-3 text-right">
                    @if ($canManage)
                        <button type="button" wire:click="edit('{{ $brand->uuid }}')" class="rounded-lg px-3 py-2 text-sm font-medium hover:bg-slate-100 dark:hover:bg-slate-700">{{ __('Edit') }}</button>
                        <button type="button" wire:click="toggle('{{ $brand->uuid }}')" class="rounded-lg px-3 py-2 text-sm font-medium hover:bg-slate-100 dark:hover:bg-slate-700">{{ $brand->is_active ? __('Deactivate') : __('Activate') }}</button>
                    @endif
                </td>
            </tr>
        @empty
            <x-ui.empty-row colspan="4">{{ __('No brands yet.') }}</x-ui.empty-row>
        @endforelse
    </x-ui.table>
    <div class="mt-4">{{ $brands->links() }}</div>

    <x-ui.modal wire:model="showForm" :title="$editing ? __('Edit brand') : __('Add brand')">
        <form wire:submit="save" class="space-y-5">
            <x-ui.field :label="__('Name')" for="brand-name" error="name"><input id="brand-name" type="text" wire:model="name" class="form-input" required></x-ui.field>
            <x-ui.field :label="__('Description')" for="brand-desc" error="description"><textarea id="brand-desc" wire:model="description" rows="2" class="form-input"></textarea></x-ui.field>
            <label class="flex min-h-11 items-center gap-3 text-sm"><input type="checkbox" wire:model="isActive" class="size-5 rounded border-slate-300 text-brand-600"> {{ __('Active') }}</label>
            <div class="flex gap-3"><button type="submit" class="btn btn-primary" wire:loading.attr="disabled">{{ __('Save') }}</button><button type="button" class="btn btn-secondary" wire:click="$set('showForm', false)">{{ __('Cancel') }}</button></div>
        </form>
    </x-ui.modal>
</div>
