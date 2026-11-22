<div>
    <x-ui.page-header :title="__('Categories')" :description="__('Organise products into categories and sub-categories.')">
        @if ($canManage)
            <x-slot:actions><button type="button" wire:click="create" class="btn btn-primary"><x-icon name="plus" /> {{ __('Add category') }}</button></x-slot:actions>
        @endif
    </x-ui.page-header>
    @include('components.ui.flash')
    @error('category') <div class="mb-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200" role="alert">{{ $message }}</div> @enderror

    <div class="mb-4 flex flex-col gap-3 sm:flex-row">
        <x-ui.search wire:model.live.debounce.300ms="search" />
        <select wire:model.live="status" class="form-input sm:w-48" aria-label="{{ __('Status') }}">
            <option value="active">{{ __('Active') }}</option>
            <option value="inactive">{{ __('Inactive') }}</option>
            <option value="all">{{ __('All') }}</option>
        </select>
    </div>

    <x-ui.table>
        <x-slot:head>
            <th class="px-4 py-3">{{ __('Name') }}</th>
            <th class="px-4 py-3">{{ __('Parent category') }}</th>
            <th class="px-4 py-3">{{ __('Products') }}</th>
            <th class="px-4 py-3">{{ __('Status') }}</th>
            <th class="px-4 py-3"><span class="sr-only">{{ __('Actions') }}</span></th>
        </x-slot:head>
        @forelse ($categories as $category)
            <tr wire:key="cat-{{ $category->uuid }}">
                <td class="px-4 py-3"><p class="font-medium">{{ $category->name }}</p><p class="text-xs text-slate-500">{{ $category->description }}</p></td>
                <td class="px-4 py-3">{{ $category->parent?->name ?? '—' }}</td>
                <td class="px-4 py-3 tabular-nums">{{ $category->products_count }}</td>
                <td class="px-4 py-3"><x-ui.status :status="$category->is_active ? 'active' : 'inactive'" /></td>
                <td class="whitespace-nowrap px-4 py-3 text-right">
                    @if ($canManage)
                        <button type="button" wire:click="edit('{{ $category->uuid }}')" class="rounded-lg px-3 py-2 text-sm font-medium hover:bg-slate-100 dark:hover:bg-slate-700">{{ __('Edit') }}</button>
                        <button type="button" wire:click="toggle('{{ $category->uuid }}')" class="rounded-lg px-3 py-2 text-sm font-medium hover:bg-slate-100 dark:hover:bg-slate-700">{{ $category->is_active ? __('Deactivate') : __('Activate') }}</button>
                        @if ($category->products_count === 0)
                            <button type="button" wire:click="delete('{{ $category->uuid }}')" wire:confirm="{{ __('Delete this category?') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50 dark:hover:bg-red-950/40">{{ __('Delete') }}</button>
                        @endif
                    @endif
                </td>
            </tr>
        @empty
            <x-ui.empty-row colspan="5">{{ __('No categories found.') }}</x-ui.empty-row>
        @endforelse
    </x-ui.table>
    <div class="mt-4">{{ $categories->links() }}</div>

    <x-ui.modal wire:model="showForm" :title="$editing ? __('Edit category') : __('Add category')">
        <form wire:submit="save" class="space-y-5">
            <x-ui.field :label="__('Name')" for="cat-name" error="name"><input id="cat-name" type="text" wire:model="name" class="form-input" required></x-ui.field>
            <x-ui.field :label="__('Parent category')" for="cat-parent" error="parent">
                <select id="cat-parent" wire:model="parent" class="form-input">
                    <option value="">{{ __('None (top level)') }}</option>
                    @foreach ($parents as $p) <option value="{{ $p->uuid }}">{{ $p->name }}</option> @endforeach
                </select>
            </x-ui.field>
            <x-ui.field :label="__('Description')" for="cat-desc" error="description"><textarea id="cat-desc" wire:model="description" rows="2" class="form-input"></textarea></x-ui.field>
            <div class="flex gap-3"><button type="submit" class="btn btn-primary" wire:loading.attr="disabled">{{ __('Save') }}</button><button type="button" class="btn btn-secondary" wire:click="$set('showForm', false)">{{ __('Cancel') }}</button></div>
        </form>
    </x-ui.modal>
</div>
