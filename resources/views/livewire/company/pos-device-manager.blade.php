<div>
    <x-ui.page-header :title="__('POS devices')" :description="__(':usage of :limit devices used', ['usage' => $usage, 'limit' => $limit ?? __('Unlimited')])">
        @if ($canManage)
            <x-slot:actions>
                <button type="button" wire:click="$toggle('showForm')" class="btn btn-primary"><x-icon name="plus" /> {{ __('Register device') }}</button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @if (session('status'))
        <div class="mb-4 rounded-xl bg-brand-50 px-4 py-3 text-sm text-brand-900 dark:bg-brand-900/30 dark:text-brand-100" role="status">{{ session('status') }}</div>
    @endif

    @if ($showForm && $canManage)
        <form wire:submit="save" class="card mb-6 grid gap-5 md:grid-cols-3">
            <div>
                <label for="device-name" class="form-label">{{ __('Device name') }}</label>
                <input id="device-name" type="text" wire:model="name" class="form-input" placeholder="{{ __('e.g. Till 1') }}" required>
                @error('name') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="device-branch" class="form-label">{{ __('Branch') }}</label>
                <select id="device-branch" wire:model="branch" class="form-input" required>
                    <option value="">{{ __('Select branch') }}</option>
                    @foreach ($branches as $b)
                        <option value="{{ $b->uuid }}">{{ $b->name }}</option>
                    @endforeach
                </select>
                @error('branch') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="device-width" class="form-label">{{ __('Receipt paper') }}</label>
                <select id="device-width" wire:model="receiptPaperWidth" class="form-input">
                    @foreach ($widths as $w)
                        <option value="{{ $w }}">{{ $w }} mm</option>
                    @endforeach
                </select>
                @error('receiptPaperWidth') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div class="flex gap-3 md:col-span-3">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">{{ __('Register device') }}</button>
                <button type="button" wire:click="$set('showForm', false)" class="btn btn-secondary">{{ __('Cancel') }}</button>
            </div>
        </form>
    @endif

    <div class="card overflow-x-auto p-0">
        <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700">
            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-500 dark:bg-slate-800">
                <tr>
                    <th class="px-4 py-3">{{ __('Device') }}</th>
                    <th class="px-4 py-3">{{ __('Branch') }}</th>
                    <th class="px-4 py-3">{{ __('Receipt paper') }} / {{ __('Selling location') }}</th>
                    <th class="px-4 py-3">{{ __('Status') }}</th>
                    <th class="px-4 py-3"><span class="sr-only">{{ __('Actions') }}</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700/60">
                @forelse ($devices as $device)
                    <tr wire:key="device-{{ $device->uuid }}">
                        <td class="px-4 py-3 font-medium">{{ $device->name }}</td>
                        <td class="px-4 py-3">{{ $device->branch->name }}</td>
                        <td class="px-4 py-3">
                            @if ($canManage && $device->status !== $revoked)
                                <div class="flex flex-wrap gap-2" x-data="{ w: '{{ $device->receipt_paper_width }}', l: '{{ $device->stockLocation?->uuid }}' }">
                                    <select x-model="w" class="h-10 rounded-lg border border-slate-300 bg-transparent px-2 text-sm dark:border-slate-600" aria-label="{{ __('Receipt paper') }}">@foreach ($widths as $w) <option value="{{ $w }}">{{ $w }} mm</option> @endforeach</select>
                                    <select x-model="l" class="h-10 rounded-lg border border-slate-300 bg-transparent px-2 text-sm dark:border-slate-600" aria-label="{{ __('Selling location') }}"><option value="">{{ __('Branch default') }}</option>@foreach ($sellableLocations[$device->branch_id] ?? [] as $sl) <option value="{{ $sl->uuid }}">{{ __($sl->name) }}</option> @endforeach</select>
                                    <button type="button" x-on:click="$wire.configure(@js($device->uuid), w, l)" class="rounded-lg px-3 text-sm font-medium text-brand-700 hover:bg-brand-50 dark:text-brand-100">{{ __('Save') }}</button>
                                </div>
                            @else
                                {{ $device->receipt_paper_width }} mm · {{ $device->stockLocation ? __($device->stockLocation->name) : __('Branch default') }}
                            @endif
                        </td>
                        <td class="px-4 py-3">{{ __(ucfirst($device->status->value)) }}</td>
                        <td class="px-4 py-3 text-right">
                            @if ($canManage && $device->status !== $revoked)
                                <button type="button" wire:click="revoke('{{ $device->uuid }}')" wire:confirm="{{ __('Revoke this device? It will no longer be able to sync.') }}"
                                        class="rounded-lg px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/40">{{ __('Revoke') }}</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-slate-500">{{ __('No POS devices registered yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
