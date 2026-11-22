@use('App\Support\Decimal')
<div>
    <x-ui.page-header :title="__('Stock movements')" :description="__(':branch · immutable ledger of every stock change.', ['branch' => $branch->name])" />
    <div class="mb-4 grid gap-3 md:grid-cols-5">
        <div class="md:col-span-2"><x-ui.search wire:model.live.debounce.300ms="search" :placeholder="__('Product, SKU or reference')" /></div>
        <select wire:model.live="type" class="form-input" aria-label="{{ __('Type') }}"><option value="">{{ __('All types') }}</option>@foreach ($types as $t) <option value="{{ $t->value }}">{{ __($t->label()) }}</option> @endforeach</select>
        <input type="date" wire:model.live="from" class="form-input" aria-label="{{ __('From') }}">
        <input type="date" wire:model.live="to" class="form-input" aria-label="{{ __('To') }}">
    </div>
    <x-ui.table>
        <x-slot:head><th class="px-4 py-3">{{ __('Date') }}</th><th class="px-4 py-3">{{ __('Product') }}</th><th class="px-4 py-3">{{ __('Type') }}</th><th class="px-4 py-3">{{ __('Location / batch') }}</th><th class="px-4 py-3 text-right">{{ __('Quantity') }}</th><th class="px-4 py-3 text-right">{{ __('Unit cost') }}</th><th class="px-4 py-3 text-right">{{ __('Balance after') }}</th><th class="px-4 py-3">{{ __('Reference / user') }}</th></x-slot:head>
        @forelse ($movements as $m)
            <tr wire:key="mv-{{ $m->id }}">
                <td class="whitespace-nowrap px-4 py-3">{{ $m->occurred_at->timezone($tz)->format('d/m/Y H:i') }}</td>
                <td class="px-4 py-3 font-medium">{{ $m->product->name }}</td>
                <td class="px-4 py-3"><x-ui.badge :color="$m->type->isInbound() ? 'green' : 'amber'">{{ __($m->type->label()) }}</x-ui.badge></td>
                <td class="px-4 py-3">{{ $m->location->name }}<p class="text-xs text-slate-500">{{ $m->batch?->batch_number }}</p></td>
                <td class="px-4 py-3 text-right tabular-nums">{{ Decimal::trim($m->quantity) }} {{ __($m->product->baseUnit->name) }}@if ($m->conversion_factor && Decimal::cmp($m->conversion_factor, '1') !== 0)<p class="text-xs text-slate-500">{{ Decimal::trim($m->entered_quantity) }} × {{ Decimal::trim($m->conversion_factor) }}</p>@endif</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ Decimal::format($m->unit_cost) }}</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ Decimal::trim($m->balance_after) }}</td>
                <td class="px-4 py-3 text-xs">{{ $m->reference }}<p class="text-slate-500">{{ $m->performer?->name }}</p></td>
            </tr>
        @empty
            <x-ui.empty-row colspan="8">{{ __('No stock movements yet.') }}</x-ui.empty-row>
        @endforelse
    </x-ui.table>
    <div class="mt-4">{{ $movements->links() }}</div>
</div>
