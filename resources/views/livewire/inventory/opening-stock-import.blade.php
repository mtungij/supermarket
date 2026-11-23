@use('App\Support\Decimal')
@php($labels = ['sku' => __('SKU'), 'barcode' => __('Barcode'), 'name' => __('Product name'), 'unit' => __('Unit'), 'quantity' => __('Quantity'), 'unit_cost' => __('Cost per unit'), 'batch_number' => __('Batch number'), 'manufactured_on' => __('Manufactured'), 'expires_on' => __('Expiry date')])
<div>
    <x-ui.page-header :title="__('Import opening stock')" :description="__('For shops with many products: fill in the template (CSV or Excel), upload it, check the preview, then post.')">
        <x-slot:actions>
            <a href="{{ route('inventory.opening-stock.template') }}" class="btn btn-secondary" data-template-link>{{ __('Download template') }}</a>
            <a href="{{ route('inventory.opening-stock.index') }}" class="btn btn-secondary">{{ __('All opening stock') }}</a>
        </x-slot:actions>
    </x-ui.page-header>

    <ol class="mb-5 grid gap-2 text-sm sm:grid-cols-4">
        @foreach ([__('Download the template: it lists your products'), __('Enter quantity and cost per unit'), __('Upload and check the preview'), __('Create the draft, then post it')] as $n => $step)
            <li class="card flex items-center gap-3 p-3"><span class="grid size-8 shrink-0 place-items-center rounded-full bg-brand-50 font-semibold text-brand-700 dark:bg-brand-900/40 dark:text-brand-100">{{ $n + 1 }}</span>{{ $step }}</li>
        @endforeach
    </ol>

    <section class="card mb-5 grid gap-5 md:grid-cols-2 lg:grid-cols-4">
        <x-ui.field :label="__('Branch').' *'" for="im-branch" error="branch">
            <select id="im-branch" wire:model.live="branch" class="form-input">@foreach ($branches as $b) <option value="{{ $b->uuid }}">{{ $b->name }}</option> @endforeach</select>
        </x-ui.field>
        <x-ui.field :label="__('Stock location').' *'" for="im-location" error="location">
            <select id="im-location" wire:model.live="location" class="form-input"><option value="">{{ __('Select…') }}</option>@foreach ($locations as $l) <option value="{{ $l->uuid }}">{{ __($l->name) }}{{ $l->is_sellable ? ' · '.__('Sellable') : '' }}</option> @endforeach</select>
        </x-ui.field>
        <x-ui.field :label="__('Opening date').' *'" for="im-date" error="openingDate"><input id="im-date" type="date" wire:model="openingDate" max="{{ now()->toDateString() }}" class="form-input"></x-ui.field>
        <x-ui.field :label="__('File (.csv or .xlsx)').' *'" for="im-file" error="file" :hint="__('Max 5 MB, up to 5,000 rows.')">
            <input id="im-file" type="file" wire:model="file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="form-input file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2">
            <p wire:loading wire:target="file" class="mt-1 text-xs text-slate-500">{{ __('Reading file…') }}</p>
        </x-ui.field>
        <x-ui.field class="md:col-span-2 lg:col-span-4" :label="__('Notes')" for="im-notes" error="notes"><input id="im-notes" type="text" wire:model="notes" class="form-input"></x-ui.field>
    </section>

    @if ($headers !== [])
        <section class="card mb-5">
            <h3 class="font-semibold">{{ __('Columns') }}</h3>
            <p class="mt-1 text-sm text-slate-500">{{ __('We matched your columns automatically. Change any that are wrong.') }}</p>
            @error('mapping') <p class="form-error">{{ $message }}</p> @enderror
            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($fields as $field)
                    <x-ui.field :label="$labels[$field].(in_array($field, ['quantity', 'unit_cost']) ? ' *' : '')" for="map-{{ $field }}">
                        <select id="map-{{ $field }}" wire:model="mapping.{{ $field }}" class="form-input">
                            <option value="">{{ __('— not in file —') }}</option>
                            @foreach ($headers as $i => $h) <option value="{{ $i }}">{{ $h !== '' ? $h : __('Column :n', ['n' => $i + 1]) }}</option> @endforeach
                        </select>
                    </x-ui.field>
                @endforeach
            </div>
            <label class="mt-4 flex min-h-11 items-start gap-3 text-sm">
                <input type="checkbox" wire:model="confirmZeroCost" class="mt-0.5 size-5 rounded border-slate-300 text-amber-600">
                <span>{{ __('Rows with cost 0 really have no cost (e.g. free samples).') }} @unless ($canZero)<span class="text-slate-500">{{ __('A manager allowed to approve zero cost must post them.') }}</span>@endunless</span>
            </label>
            <div class="mt-4"><button type="button" wire:click="analyse" wire:loading.attr="disabled" wire:target="analyse,file" class="btn btn-primary">
                <span wire:loading.remove wire:target="analyse">{{ __('Check file') }}</span><span wire:loading wire:target="analyse">{{ __('Checking…') }}</span></button></div>
        </section>
    @endif

    @if ($result)
        @php($ok = $result['errors'] === [])
        <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4" data-import-summary>
            <div class="card p-4"><p class="text-xs uppercase text-slate-500">{{ __('Rows to import') }}</p><p class="mt-1 text-lg font-semibold">{{ $result['totals']['rows'] }}</p><p class="text-xs text-slate-500">{{ __(':n rows without quantity skipped', ['n' => $result['skipped']]) }}</p></div>
            <div class="card p-4"><p class="text-xs uppercase text-slate-500">{{ __('Products') }}</p><p class="mt-1 text-lg font-semibold">{{ $result['totals']['products'] }}</p></div>
            <div class="card p-4"><p class="text-xs uppercase text-slate-500">{{ __('Total quantity') }}</p><p class="mt-1 text-lg font-semibold tabular-nums">{{ $ok ? Decimal::trim($result['totals']['quantity']) : '—' }}</p></div>
            <div class="card p-4"><p class="text-xs uppercase text-slate-500">{{ __('Opening value') }}</p><p class="mt-1 text-lg font-semibold tabular-nums">{{ $ok ? $currency.' '.Decimal::format($result['totals']['value']) : '—' }}</p></div>
        </div>

        @if (! $ok)
            <section class="mb-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200" role="alert" data-import-errors>
                <p class="font-semibold">{{ __(':n rows have problems. Nothing will be saved until they are fixed.', ['n' => count(array_filter(array_keys($result['errors'])))]) }}</p>
                <ul class="mt-2 max-h-72 list-disc space-y-1 overflow-y-auto pl-5">
                    @foreach ($result['errors'] as $row => $messages)
                        @foreach ($messages as $m)<li>@if ($row > 0){{ __('Row :row', ['row' => $row]) }}: @endif{{ $m }}</li>@endforeach
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($result['preview'] !== [])
            <x-ui.table class="mb-5">
                <x-slot:head>
                    <th class="px-4 py-3">{{ __('Row') }}</th><th class="px-4 py-3">{{ __('Product') }}</th><th class="px-4 py-3 text-right">{{ __('Quantity') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('Cost per unit') }}</th><th class="px-4 py-3">{{ __('Batch / expiry') }}</th><th class="px-4 py-3">{{ __('Check') }}</th>
                </x-slot:head>
                @foreach ($result['preview'] as $p)
                    <tr @class(['bg-red-50/60 dark:bg-red-950/20' => $p['error']])>
                        <td class="px-4 py-2 tabular-nums">{{ $p['row'] }}</td>
                        <td class="px-4 py-2">{{ $p['name'] }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $p['quantity'] }} {{ __($p['unit'] ?? '') }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $p['unit_cost'] }}</td>
                        <td class="px-4 py-2">{{ $p['batch'] ?: '—' }}@if ($p['expires_on']) · {{ $p['expires_on'] }}@endif</td>
                        <td class="px-4 py-2">@if ($p['error'])<span class="text-red-700 dark:text-red-300">{{ $p['error'] }}</span>@else<span class="text-brand-700 dark:text-brand-100">✓ {{ __('OK') }}</span>@endif</td>
                    </tr>
                @endforeach
            </x-ui.table>
            @if ($result['totals']['rows'] > count($result['preview']))<p class="mb-5 text-xs text-slate-500">{{ __('Showing the first :n rows.', ['n' => count($result['preview'])]) }}</p>@endif
        @endif

        @if ($ok)
            <section class="card space-y-4">
                @if ($result['totals']['zero_cost_rows'] > 0)
                    <x-ui.field :label="__('Reason for zero-cost stock').' *'" for="im-zero" error="zeroCostReason"><input id="im-zero" type="text" wire:model="zeroCostReason" class="form-input" maxlength="255"></x-ui.field>
                @endif
                <p class="text-sm text-slate-600 dark:text-slate-300">{{ __('All rows are valid. Creating the draft does not change stock; you post it on the next page.') }}</p>
                <button type="button" wire:click="createDraft" wire:loading.attr="disabled" wire:target="createDraft" class="btn btn-primary" data-create-draft>{{ __('Create draft from file') }}</button>
            </section>
        @endif
    @endif
</div>
