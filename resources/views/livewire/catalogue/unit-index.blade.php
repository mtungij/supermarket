<div>
    <x-ui.page-header :title="__('Units')" :description="__('Measurement units convert by fixed ratios. Packaging units (pack, carton…) are converted per product on each product page.')">
        @if ($canManage)
            <x-slot:actions><button type="button" wire:click="$set('showForm', true)" class="btn btn-primary"><x-icon name="plus" /> {{ __('Add unit') }}</button></x-slot:actions>
        @endif
    </x-ui.page-header>
    @include('components.ui.flash')
    @error('unit') <div class="mb-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200" role="alert">{{ $message }}</div> @enderror

    <x-ui.table>
        <x-slot:head>
            <th class="px-4 py-3">{{ __('Name') }}</th><th class="px-4 py-3">{{ __('Code') }}</th><th class="px-4 py-3">{{ __('Dimension') }}</th>
            <th class="px-4 py-3">{{ __('Conversion') }}</th><th class="px-4 py-3">{{ __('Base unit of') }}</th><th class="px-4 py-3">{{ __('Status') }}</th><th class="px-4 py-3"></th>
        </x-slot:head>
        @foreach ($units as $unit)
            <tr wire:key="unit-{{ $unit->uuid }}">
                <td class="px-4 py-3 font-medium">{{ __($unit->name) }}</td>
                <td class="px-4 py-3 font-mono text-xs">{{ $unit->code }}</td>
                <td class="px-4 py-3">{{ __($unit->dimension->label()) }}</td>
                <td class="px-4 py-3 text-slate-500">{{ $unit->ratio !== null ? '= '.\App\Support\Decimal::trim($unit->ratio).' '.['mass' => 'kg', 'volume' => 'l', 'length' => 'm'][$unit->dimension->value] : __('Per product') }}</td>
                <td class="px-4 py-3 tabular-nums">{{ trans_choice(':count product|:count products', $usage[$unit->id] ?? 0) }}</td>
                <td class="px-4 py-3"><x-ui.status :status="$unit->is_active ? 'active' : 'inactive'" /></td>
                <td class="px-4 py-3 text-right">
                    @if ($canManage)
                        <button type="button" wire:click="toggle('{{ $unit->uuid }}')" class="rounded-lg px-3 py-2 text-sm font-medium hover:bg-slate-100 dark:hover:bg-slate-700">{{ $unit->is_active ? __('Deactivate') : __('Activate') }}</button>
                    @endif
                </td>
            </tr>
        @endforeach
    </x-ui.table>

    <x-ui.modal wire:model="showForm" :title="__('Add unit')">
        <form wire:submit="save" class="grid gap-5 sm:grid-cols-2">
            <x-ui.field :label="__('Name')" for="unit-name" error="name"><input id="unit-name" type="text" wire:model="name" class="form-input" placeholder="{{ __('e.g. Dozen') }}" required></x-ui.field>
            <x-ui.field :label="__('Code')" for="unit-code" error="code"><input id="unit-code" type="text" wire:model="code" class="form-input" placeholder="dz" required></x-ui.field>
            <x-ui.field :label="__('Dimension')" for="unit-dim" error="dimension">
                <select id="unit-dim" wire:model.live="dimension" class="form-input">@foreach ($dimensions as $d) <option value="{{ $d->value }}">{{ __($d->label()) }}</option> @endforeach</select>
            </x-ui.field>
            @if ($dimension !== 'count')
                <x-ui.field :label="__('Ratio to kg / l / m')" for="unit-ratio" error="ratio" :hint="__('e.g. 0.000001 for milligram')"><input id="unit-ratio" type="text" inputmode="decimal" wire:model="ratio" class="form-input" required></x-ui.field>
            @endif
            <div class="flex gap-3 sm:col-span-2"><button type="submit" class="btn btn-primary">{{ __('Save') }}</button><button type="button" class="btn btn-secondary" wire:click="$set('showForm', false)">{{ __('Cancel') }}</button></div>
        </form>
    </x-ui.modal>
</div>
