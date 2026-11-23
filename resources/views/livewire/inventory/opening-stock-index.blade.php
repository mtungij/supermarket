@use('App\Support\Decimal')
<div>
    <x-ui.page-header :title="__('Opening stock')" :description="__('Stock you already owned before using this system. For new deliveries use Purchases; for corrections use Stock adjustments.')">
        @if ($canManage)
            <x-slot:actions>
                <a href="{{ route('inventory.opening-stock.import') }}" class="btn btn-secondary">{{ __('Import CSV / Excel') }}</a>
                <a href="{{ route('inventory.opening-stock.create') }}" class="btn btn-primary"><x-icon name="plus" /> {{ __('New opening stock') }}</a>
            </x-slot:actions>
        @endif
    </x-ui.page-header>
    @include('components.ui.flash')

    <div class="mb-5 grid gap-3 sm:grid-cols-3">
        <x-ui.stat-card :label="__('Posted opening value')" :value="$currency.' '.Decimal::format($totals['posted_value'])" icon="chart" />
        <x-ui.stat-card :label="__('Posted documents')" :value="$totals['posted']" icon="box" />
        <x-ui.stat-card :label="__('Drafts')" :value="$totals['drafts']" :hint="__('Drafts do not change stock.')" icon="tag" />
    </div>

    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <input type="search" wire:model.live.debounce.300ms="search" class="form-input" placeholder="{{ __('Reference, e.g. OPS-000001') }}" aria-label="{{ __('Search') }}">
        <select wire:model.live="status" class="form-input" aria-label="{{ __('Status') }}"><option value="">{{ __('All statuses') }}</option>@foreach ($statuses as $s) <option value="{{ $s->value }}">{{ __($s->label()) }}</option> @endforeach</select>
        <select wire:model.live="branch" class="form-input" aria-label="{{ __('Branch') }}"><option value="">{{ __('All branches') }}</option>@foreach ($branches as $b) <option value="{{ $b->uuid }}">{{ $b->name }}</option> @endforeach</select>
    </div>

    <x-ui.table>
        <x-slot:head>
            <th class="px-4 py-3">{{ __('Reference') }}</th>
            <th class="px-4 py-3">{{ __('Branch') }} / {{ __('Location') }}</th>
            <th class="px-4 py-3">{{ __('Opening date') }}</th>
            <th class="px-4 py-3 text-right">{{ __('Products') }}</th>
            <th class="px-4 py-3 text-right">{{ __('Value') }}</th>
            <th class="px-4 py-3">{{ __('Status') }}</th>
            <th class="px-4 py-3">{{ __('Created / posted by') }}</th>
        </x-slot:head>
        @forelse ($documents as $d)
            <tr wire:key="os-{{ $d->uuid }}">
                <td class="px-4 py-3"><a href="{{ route('inventory.opening-stock.show', $d) }}" class="font-semibold text-brand-700 hover:underline dark:text-brand-100">{{ $d->reference }}</a>@if ($d->source === 'import')<span class="ml-1 text-xs text-slate-500">· {{ __('import') }}</span>@endif</td>
                <td class="px-4 py-3">{{ $d->branch->name }} · {{ __($d->location->name) }}</td>
                <td class="px-4 py-3">{{ $d->opening_date->format('d/m/Y') }}</td>
                <td class="px-4 py-3 text-right tabular-nums">{{ $d->line_count }}</td>
                <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ Decimal::format($d->total_value) }}</td>
                <td class="px-4 py-3"><x-ui.status :status="$d->status" /></td>
                <td class="px-4 py-3 text-sm">{{ $d->creator?->name ?? '—' }}@if ($d->poster) / {{ $d->poster->name }}@endif</td>
            </tr>
        @empty
            <tr><td colspan="7" class="px-4 py-10 text-center text-slate-500">{{ __('No opening stock yet. Use "New opening stock" to enter the stock you already have.') }}</td></tr>
        @endforelse
    </x-ui.table>
    <div class="mt-4">{{ $documents->links() }}</div>
</div>
