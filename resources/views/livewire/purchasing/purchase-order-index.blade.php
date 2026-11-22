@use('App\Support\Decimal')
<div>
    <x-ui.page-header :title="__('Purchases')" :description="__('Create a purchase, send the PO, then receive the goods. Stock changes only when goods are received.')">
        @if ($canCreate)
            <x-slot:actions><a href="{{ route('purchasing.orders.create') }}" class="btn btn-primary"><x-icon name="plus" /> {{ __('Create purchase') }}</a></x-slot:actions>
        @endif
    </x-ui.page-header>
    @include('components.ui.flash')
    @error('status') <div class="mb-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200" role="alert">{{ $message }}</div> @enderror

    <div class="mb-4 grid gap-3 md:grid-cols-3">
        <x-ui.search wire:model.live.debounce.300ms="search" :placeholder="__('Purchase number')" />
        <select wire:model.live="status" class="form-input" aria-label="{{ __('Status') }}"><option value="">{{ __('All statuses') }}</option>@foreach ($statuses as $s) <option value="{{ $s->value }}">{{ __($s->label()) }}</option> @endforeach</select>
        <select wire:model.live="supplier" class="form-input" aria-label="{{ __('Supplier') }}"><option value="">{{ __('All suppliers') }}</option>@foreach ($suppliers as $s) <option value="{{ $s->uuid }}">{{ $s->name }}</option> @endforeach</select>
    </div>

    <x-ui.table>
        <x-slot:head>
            <th class="px-4 py-3">{{ __('Purchase number') }}</th><th class="px-4 py-3">{{ __('Supplier') }}</th><th class="px-4 py-3">{{ __('Branch') }}</th>
            <th class="px-4 py-3">{{ __('Purchase date') }}</th><th class="px-4 py-3 text-right">{{ __('Total') }}</th><th class="px-4 py-3 text-right">{{ __('Paid') }}</th>
            <th class="px-4 py-3 text-right" title="{{ __('Value of goods received (incl. tax) minus payments') }}">{{ __('Balance') }}</th><th class="px-4 py-3">{{ __('Status') }}</th><th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
        </x-slot:head>
        @forelse ($orders as $o)
            <tr wire:key="po-{{ $o->uuid }}">
                <td class="px-4 py-3"><a href="{{ route('purchasing.orders.show', $o) }}" class="font-medium text-brand-700 hover:underline dark:text-brand-100">{{ $o->reference }}</a></td>
                <td class="px-4 py-3">{{ $o->supplier->name }}</td>
                <td class="px-4 py-3">{{ $o->branch->name }}</td>
                <td class="whitespace-nowrap px-4 py-3">{{ $o->order_date->format('d/m/Y') }}</td>
                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ Decimal::format($o->total) }}</td>
                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ Decimal::format($o->paid) }}</td>
                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ Decimal::format($o->balance) }}</td>
                <td class="px-4 py-3">
                    <x-ui.status :status="$o->status" />
                    @if ($o->received_percent > 0)
                        <div class="mt-1.5 h-1.5 w-24 rounded-full bg-slate-200 dark:bg-slate-700" title="{{ __(':p% received', ['p' => $o->received_percent]) }}"><div class="h-1.5 rounded-full bg-brand-500" style="width: {{ min(100, $o->received_percent) }}%"></div></div>
                    @endif
                </td>
                <td class="whitespace-nowrap px-4 py-3 text-right">
                    <div class="inline-flex flex-wrap justify-end gap-1" x-data>
                        <a href="{{ route('purchasing.orders.show', $o) }}" class="rounded-lg px-3 py-2 text-sm font-medium hover:bg-slate-100 dark:hover:bg-slate-700">{{ __('View') }}</a>
                        @if ($o->actions['edit']) <a href="{{ route('purchasing.orders.edit', $o) }}" class="rounded-lg px-3 py-2 text-sm font-medium hover:bg-slate-100 dark:hover:bg-slate-700">{{ __('Edit') }}</a> @endif
                        @if ($o->actions['send']) <button type="button" wire:click="send('{{ $o->uuid }}')" wire:loading.attr="disabled" wire:confirm="{{ __('Send this purchase order?') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-brand-700 hover:bg-brand-50 dark:text-brand-100 dark:hover:bg-brand-900/30">{{ __('Send PO') }}</button> @endif
                        @if ($o->actions['receive']) <a href="{{ route('purchasing.orders.receive', $o) }}" class="rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-700">{{ __('Receive') }}</a> @endif
                        @if ($o->actions['cancel']) <button type="button" x-on:click="const r = prompt(@js(__('Reason for cancelling :ref', ['ref' => $o->reference]))); if (r) $wire.cancel(@js($o->uuid), r)" class="rounded-lg px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50 dark:hover:bg-red-950/40">{{ __('Cancel') }}</button> @endif
                    </div>
                </td>
            </tr>
        @empty
            <x-ui.empty-row colspan="9">{{ __('No purchases found.') }}</x-ui.empty-row>
        @endforelse
    </x-ui.table>
    <div class="mt-4">{{ $orders->links() }}</div>
</div>
