@use('App\Support\Decimal')
<div class="space-y-4">
    <x-ui.page-header :title="$scopeOwn ? __('My Sales') : __('Sales')" :description="($range[0]->format('d/m/Y') === $range[1]->format('d/m/Y') ? $range[0]->format('d/m/Y') : $range[0]->format('d/m/Y').' – '.$range[1]->format('d/m/Y')).' · '.($scopeOwn ? __('Sales you completed') : __('Sales of the branches you can access'))">
        @can('pos.access')
            <x-slot:actions><a href="{{ route('pos') }}" class="btn btn-primary"><x-icon name="cart" /> {{ __('Open POS') }}</a></x-slot:actions>
        @endcan
    </x-ui.page-header>
    @include('components.ui.flash')

    {{-- Period + scope filters (shared by both views) --}}
    <div class="card space-y-3 p-3 sm:p-4">
        <div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('Period') }}">
            @foreach (['today' => __('Today'), 'yesterday' => __('Yesterday'), 'this_week' => __('This week'), 'this_month' => __('This month'), 'custom' => __('Custom range'), 'all' => __('All time')] as $key => $label)
                <button type="button" wire:click="setPeriod('{{ $key }}')" @class(['min-h-11 rounded-xl px-4 text-sm font-medium', 'bg-brand-600 text-white' => $period === $key, 'border border-slate-300 dark:border-slate-600' => $period !== $key]) aria-pressed="{{ $period === $key ? 'true' : 'false' }}">{{ $label }}</button>
            @endforeach
        </div>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @if ($period === 'custom')
                <input type="date" wire:model.live="from" class="form-input" aria-label="{{ __('From') }}">
                <input type="date" wire:model.live="to" class="form-input" aria-label="{{ __('To') }}">
            @endif
            @if ($branches->count() > 1)
                <select wire:model.live="branch" class="form-input" aria-label="{{ __('Branch') }}"><option value="">{{ __('All branches') }}</option>@foreach ($branches as $b) <option value="{{ $b->uuid }}">{{ $b->name }}</option> @endforeach</select>
            @endif
            @if ($cashiers->isNotEmpty())
                <select wire:model.live="cashier" class="form-input" aria-label="{{ __('Cashier') }}"><option value="">{{ __('All cashiers') }}</option>@foreach ($cashiers as $c) <option value="{{ $c->id }}">{{ $c->name }}</option> @endforeach</select>
            @endif
        </div>
    </div>

    {{-- Summary cards --}}
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4" aria-live="polite">
        <x-ui.stat-card :label="__('Total sales')" :value="$currency.' '.Decimal::format($summary['gross_sales'])" :hint="__('VAT included: :tax', ['tax' => Decimal::format($summary['vat'])])" icon="receipt" />
        <x-ui.stat-card :label="__('Completed transactions')" :value="$summary['transactions']" icon="chart" />
        <x-ui.stat-card :label="__('Items sold')" :value="collect($summary['items_by_unit'])->map(fn ($u) => $u['qty'].' '.__($u['unit']))->join(' · ') ?: '0'" icon="box" />
        <x-ui.stat-card :label="__('Net sales')" :value="Decimal::format($summary['net_sales'])" :hint="__('After refunds of :r', ['r' => Decimal::format($summary['refunds'])])" icon="wallet" />
        <x-ui.stat-card :label="__('Cash collected')" :value="Decimal::format($summary['cash'])" :hint="__('Excludes change given: :c', ['c' => Decimal::format($summary['change_given'])])" icon="wallet" />
        <x-ui.stat-card :label="__('Mobile Money collected')" :value="Decimal::format($summary['mobile_money'])" icon="device" />
        <x-ui.stat-card :label="__('Card / Bank collected')" :value="Decimal::format($summary['card_bank'])" :hint="Decimal::isPositive($summary['other']) || Decimal::isPositive($summary['exchange_credit']) ? __('Other: :o · Exchange credit: :e', ['o' => Decimal::format($summary['other']), 'e' => Decimal::format($summary['exchange_credit'])]) : null" icon="credit-card" />
        <x-ui.stat-card :label="__('Refunds')" :value="Decimal::format($summary['refunds'])" :hint="__(':n returns', ['n' => $summary['returns']])" icon="truck" />
    </div>

    {{-- Tabs --}}
    <div class="flex gap-2 border-b border-slate-200 dark:border-slate-700" role="tablist">
        @foreach (['products' => __('Products Sold'), 'transactions' => __('Transactions')] as $key => $label)
            <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="setTab('{{ $key }}')" @class(['-mb-px min-h-12 border-b-2 px-4 text-base font-semibold', 'border-brand-600 text-brand-700 dark:text-brand-100' => $tab === $key, 'border-transparent text-slate-500' => $tab !== $key])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($tab === 'products')
        {{-- Products Sold: cards on phones (no horizontal scroll), table from lg up --}}
        <div class="space-y-2 lg:hidden">
            @forelse ($products as $p)
                <div class="card p-4" wire:key="pm-{{ $loop->index }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0"><p class="break-words text-base font-semibold">{{ $p->product_name }}</p><p class="text-xs text-slate-500">{{ $p->sku }}</p></div>
                        <div class="shrink-0 text-right"><p class="text-2xl font-bold tabular-nums">{{ $p->net_quantity }}</p><p class="text-xs text-slate-500">{{ __($p->unit) }}</p></div>
                    </div>
                    <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                        <dt class="text-slate-500">{{ __('Quantity sold') }}</dt><dd class="text-right tabular-nums">{{ $p->quantity }}</dd>
                        <dt class="text-slate-500">{{ __('Unit price') }}</dt><dd class="text-right tabular-nums">{{ $p->single_price ? Decimal::format($p->min_price) : Decimal::format($p->min_price).' – '.Decimal::format($p->max_price) }}</dd>
                        <dt class="text-slate-500">{{ __('Total sales') }}</dt><dd class="text-right tabular-nums">{{ Decimal::format($p->gross) }}</dd>
                        @if ($p->returned_quantity !== '0')
                            <dt class="text-slate-500">{{ __('Returned') }}</dt><dd class="text-right tabular-nums text-red-600">−{{ $p->returned_quantity }} · −{{ Decimal::format($p->refunded) }}</dd>
                        @endif
                        <dt class="font-semibold">{{ __('Net sales') }}</dt><dd class="text-right font-semibold tabular-nums">{{ Decimal::format($p->net) }}</dd>
                    </dl>
                </div>
            @empty
                <div class="card text-center text-sm text-slate-500">{{ __('No products sold in this period.') }}</div>
            @endforelse
        </div>
        <div class="hidden lg:block">
            <x-ui.table>
                <x-slot:head>
                    <th class="px-4 py-3">{{ __('Product') }}</th><th class="px-4 py-3">{{ __('Unit') }}</th><th class="px-4 py-3 text-right">{{ __('Quantity sold') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('Unit price') }}</th><th class="px-4 py-3 text-right">{{ __('Total sales') }}</th><th class="px-4 py-3 text-right">{{ __('Returned') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('Net quantity') }}</th><th class="px-4 py-3 text-right">{{ __('Net sales') }}</th>
                </x-slot:head>
                @forelse ($products as $p)
                    <tr wire:key="pd-{{ $loop->index }}">
                        <td class="px-4 py-3"><p class="text-base font-semibold">{{ $p->product_name }}</p><p class="text-xs text-slate-500">{{ $p->sku }} · {{ trans_choice(':count sale|:count sales', $p->transactions) }}</p></td>
                        <td class="px-4 py-3">{{ __($p->unit) }}</td>
                        <td class="px-4 py-3 text-right text-lg font-semibold tabular-nums">{{ $p->quantity }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            @if ($p->single_price) {{ Decimal::format($p->min_price) }}
                            @else {{ Decimal::format($p->min_price) }} – {{ Decimal::format($p->max_price) }}<p class="text-xs text-slate-500">{{ __('avg :a', ['a' => Decimal::format($p->average_price)]) }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ Decimal::format($p->gross) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">@if ($p->returned_quantity !== '0')<span class="text-red-600">−{{ $p->returned_quantity }}</span><p class="text-xs text-slate-500">−{{ Decimal::format($p->refunded) }}</p>@else — @endif</td>
                        <td class="px-4 py-3 text-right text-lg font-bold tabular-nums">{{ $p->net_quantity }}</td>
                        <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ Decimal::format($p->net) }}</td>
                    </tr>
                @empty
                    <x-ui.empty-row colspan="8">{{ __('No products sold in this period.') }}</x-ui.empty-row>
                @endforelse
            </x-ui.table>
        </div>
        <p class="text-xs text-slate-500">{{ __('Amounts are the historical selling prices actually charged (after line discounts). Returned figures are returns against these sales.') }}</p>
    @else
        {{-- Transactions (sale numbers kept for audit, returns and reprints) --}}
        <div class="grid gap-3 sm:grid-cols-3">
            <x-ui.search wire:model.live.debounce.300ms="search" :placeholder="__('Sale number')" />
            <input type="search" wire:model.live.debounce.300ms="customer" class="form-input" placeholder="{{ __('Customer') }}" aria-label="{{ __('Customer') }}">
            <select wire:model.live="method" class="form-input" aria-label="{{ __('Payment method') }}"><option value="">{{ __('All payment methods') }}</option>@foreach ($methods as $m) <option value="{{ $m->uuid }}">{{ __($m->name) }}</option> @endforeach</select>
        </div>
        <div class="space-y-2 md:hidden">
            @forelse ($sales as $s)
                @php($status = $s->returnStatus((string) ($s->returned_total ?? '0')))
                <div class="card p-3" wire:key="tm-{{ $s->uuid }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0"><p class="font-semibold">{{ $s->sale_number }}</p><p class="text-xs text-slate-500">{{ $s->sold_at->timezone($tz)->format('d/m/Y H:i') }} · {{ $s->cashier->name }}</p></div>
                        <p class="text-right text-lg font-bold tabular-nums">{{ Decimal::format($s->total) }}</p>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">{{ $s->payments->pluck('method_name')->unique()->map(fn ($n) => __($n))->join(', ') }} · <x-ui.badge :color="$status === 'completed' ? 'green' : ($status === 'returned' ? 'red' : 'amber')">{{ __(ucfirst(str_replace('_', ' ', $status))) }}</x-ui.badge></p>
                    <div class="mt-2 flex gap-2">
                        <a href="{{ route('sales.show', $s) }}" class="btn btn-secondary flex-1 py-2">{{ __('View') }}</a>
                        <button type="button" x-data x-on:click="window.printReceipt(@js(route('sales.receipt', ['sale' => $s->uuid, 'autoprint' => 1])))" class="btn btn-secondary flex-1 py-2">{{ __('Reprint') }}</button>
                    </div>
                </div>
            @empty
                <div class="card text-center text-sm text-slate-500">{{ __('No sales found.') }}</div>
            @endforelse
        </div>
        <div class="hidden md:block">
            <x-ui.table>
                <x-slot:head>
                    <th class="px-4 py-3">{{ __('Sale number') }}</th><th class="px-4 py-3">{{ __('Date') }}</th><th class="px-4 py-3">{{ __('Cashier') }}</th><th class="px-4 py-3">{{ __('Branch') }}</th>
                    <th class="px-4 py-3">{{ __('Customer') }}</th><th class="px-4 py-3 text-right">{{ __('Total') }}</th><th class="px-4 py-3">{{ __('Payment') }}</th><th class="px-4 py-3">{{ __('Status') }}</th><th class="px-4 py-3"></th>
                </x-slot:head>
                @forelse ($sales as $s)
                    @php($status = $s->returnStatus((string) ($s->returned_total ?? '0')))
                    <tr wire:key="sale-{{ $s->uuid }}">
                        <td class="px-4 py-3"><a href="{{ route('sales.show', $s) }}" class="font-medium text-brand-700 hover:underline dark:text-brand-100">{{ $s->sale_number }}</a><p class="text-xs text-slate-500">{{ trans_choice(':count item|:count items', $s->items_count) }}</p></td>
                        <td class="whitespace-nowrap px-4 py-3">{{ $s->sold_at->timezone($tz)->format('d/m/Y H:i') }}</td>
                        <td class="px-4 py-3">{{ $s->cashier->name }}</td>
                        <td class="px-4 py-3">{{ $s->branch->name }}</td>
                        <td class="px-4 py-3">{{ $s->customer?->name ?? __('Walk-in') }}</td>
                        <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ Decimal::format($s->total) }}</td>
                        <td class="px-4 py-3 text-xs">{{ $s->payments->map(fn ($p) => __($p->method_name).' '.Decimal::format($p->amount))->join(', ') }}</td>
                        <td class="px-4 py-3"><x-ui.badge :color="$status === 'completed' ? 'green' : ($status === 'returned' ? 'red' : 'amber')">{{ __(ucfirst(str_replace('_', ' ', $status))) }}</x-ui.badge></td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            <button type="button" x-data x-on:click="window.printReceipt(@js(route('sales.receipt', ['sale' => $s->uuid, 'autoprint' => 1])))" class="inline-flex min-h-10 items-center rounded-lg px-3 py-2 text-sm font-medium hover:bg-slate-100 dark:hover:bg-slate-700">{{ __('Reprint') }}</button>
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-row colspan="9">{{ __('No sales found.') }}</x-ui.empty-row>
                @endforelse
            </x-ui.table>
        </div>
        <div>{{ $sales->links() }}</div>
    @endif
</div>
