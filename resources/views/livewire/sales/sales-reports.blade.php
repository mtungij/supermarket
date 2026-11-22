@use('App\Support\Decimal')
<div class="space-y-6">
    <x-ui.page-header :title="__('Sales reports')" :description="__('From posted sales and returns. Cash change is never counted as revenue.')" />
    <div class="grid gap-3 sm:grid-cols-3">
        <input type="date" wire:model.live="from" class="form-input" aria-label="{{ __('From') }}">
        <input type="date" wire:model.live="to" class="form-input" aria-label="{{ __('To') }}">
        <select wire:model.live="branch" class="form-input" aria-label="{{ __('Branch') }}"><option value="">{{ __('All my branches') }}</option>@foreach ($branches as $b) <option value="{{ $b->uuid }}">{{ $b->name }}</option> @endforeach</select>
    </div>
    @php($s = $report['summary'])
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card :label="__('Total sales')" :value="Decimal::format($s['gross_sales'])" :hint="__(':n transactions', ['n' => $s['transactions']])" icon="receipt" />
        <x-ui.stat-card :label="__('Returns & refunds')" :value="Decimal::format($s['refunds'])" :hint="__(':n returns', ['n' => $s['returns']])" icon="truck" />
        <x-ui.stat-card :label="__('Net sales')" :value="Decimal::format($s['net_sales'])" :hint="__('Items sold: :n', ['n' => $s['items_sold']])" icon="chart" />
        <x-ui.stat-card :label="__('Discounts')" :value="Decimal::format($s['discounts'])" :hint="__('Tax: :t', ['t' => Decimal::format($s['tax'])])" icon="tag" />
        @if ($financial)
            <x-ui.stat-card :label="__('Net revenue (excl. tax)')" :value="Decimal::format($s['net_revenue_ex_tax'])" icon="wallet" />
            <x-ui.stat-card :label="__('COGS')" :value="Decimal::format($s['cogs'])" icon="box" />
            <x-ui.stat-card :label="__('Estimated gross profit')" :value="Decimal::format($s['gross_profit'])" icon="chart" />
        @endif
    </div>
    <div class="grid gap-4 lg:grid-cols-2">
        @foreach ([['by_product', __('Top-selling products')], ['by_category', __('Sales by category')], ['by_cashier', __('Sales by cashier')], ['by_branch', __('Sales by branch')], ['by_payment', __('Sales by payment method')], ['refunds_by_method', __('Refunds by method')]] as [$key, $title])
            <section class="card">
                <h3 class="mb-3 font-semibold">{{ $title }}</h3>
                <table class="w-full text-sm">
                    @forelse ($report[$key] as $row)
                        <tr class="border-b border-slate-100 last:border-0 dark:border-slate-700"><td class="py-2">{{ __($row->label) }}</td>
                            <td class="py-2 text-right text-slate-500">{{ isset($row->qty) ? Decimal::trim(Decimal::round((string) $row->qty, 4)) : (isset($row->transactions) ? $row->transactions : ($row->payments ?? $row->returns ?? '')) }}</td>
                            <td class="py-2 text-right font-medium tabular-nums">{{ Decimal::format((string) $row->total) }}</td>
                            @if ($financial && isset($row->cost))<td class="py-2 text-right text-xs text-slate-500">{{ __('GP') }} {{ Decimal::format(Decimal::sub((string) $row->net, (string) $row->cost)) }}</td>@endif
                        </tr>
                    @empty
                        <tr><td class="py-4 text-center text-slate-500">{{ __('No data for this period.') }}</td></tr>
                    @endforelse
                </table>
            </section>
        @endforeach
    </div>
</div>
