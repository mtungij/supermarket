<div>
    <x-ui.page-header :title="__('Branches')" :description="__(':usage of :limit branches used', ['usage' => $usage, 'limit' => $limit ?? __('Unlimited')])">
        @if ($canCreate)
            <x-slot:actions>
                <button type="button" wire:click="$toggle('showForm')" class="btn btn-primary"><x-icon name="plus" /> {{ __('Add branch') }}</button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @if (session('status'))
        <div class="mb-4 rounded-xl bg-brand-50 px-4 py-3 text-sm text-brand-900 dark:bg-brand-900/30 dark:text-brand-100" role="status">{{ session('status') }}</div>
    @endif

    @if ($showForm && $canCreate)
        <form wire:submit="save" class="card mb-6 grid gap-5 md:grid-cols-2">
            <div>
                <label for="name" class="form-label">{{ __('Branch name') }}</label>
                <input id="name" type="text" wire:model="name" class="form-input" required>
                @error('name') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="code" class="form-label">{{ __('Branch code') }} <span class="text-slate-400">({{ __('optional') }})</span></label>
                <input id="code" type="text" wire:model="code" class="form-input" placeholder="BR002">
                @error('code') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="phone" class="form-label">{{ __('Phone') }}</label>
                <input id="phone" type="tel" wire:model="phone" class="form-input">
                @error('phone') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="address" class="form-label">{{ __('Address') }}</label>
                <input id="address" type="text" wire:model="address" class="form-input">
                @error('address') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div class="flex gap-3 md:col-span-2">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">{{ __('Save branch') }}</button>
                <button type="button" wire:click="$set('showForm', false)" class="btn btn-secondary">{{ __('Cancel') }}</button>
            </div>
        </form>
    @endif

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($branches as $branch)
            <a href="{{ route('company.branches.show', $branch) }}" wire:key="branch-{{ $branch->uuid }}" class="card transition hover:border-brand-500">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="truncate font-semibold">{{ $branch->name }}</p>
                        <p class="text-xs text-slate-500">{{ $branch->code }}</p>
                    </div>
                    @if ($branch->is_head_office)
                        <span class="rounded-full bg-brand-50 px-2.5 py-1 text-xs font-medium text-brand-700 dark:bg-brand-900/40 dark:text-brand-100">{{ __('Head office') }}</span>
                    @endif
                </div>
                <p class="mt-4 text-sm text-slate-500">{{ trans_choice(':count POS device|:count POS devices', $branch->pos_devices_count) }}</p>
            </a>
        @endforeach
    </div>
</div>
