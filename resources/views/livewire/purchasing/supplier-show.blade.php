<div class="space-y-6">
    <x-ui.page-header :title="$supplier->name" :description="collect([$supplier->contact_person, $supplier->phone, $supplier->email])->filter()->join(' · ')" />
    <div class="grid gap-4 md:grid-cols-3">
        <x-ui.stat-card :label="__('Outstanding balance')" :value="\App\Support\Decimal::format($balance)" :hint="__('Posted receipts minus payments (payments arrive in a later phase).')" icon="wallet" />
        <div class="card text-sm md:col-span-2">
            <dl class="grid gap-3 sm:grid-cols-2">
                <div><dt class="text-slate-500">{{ __('Tax identifier (TIN)') }}</dt><dd>{{ $supplier->tax_id ?? '—' }}</dd></div>
                <div><dt class="text-slate-500">{{ __('Status') }}</dt><dd><x-ui.status :status="$supplier->is_active ? 'active' : 'inactive'" /></dd></div>
                <div class="sm:col-span-2"><dt class="text-slate-500">{{ __('Address') }}</dt><dd>{{ $supplier->address ?? '—' }}</dd></div>
                @if ($supplier->notes)<div class="sm:col-span-2"><dt class="text-slate-500">{{ __('Notes') }}</dt><dd>{{ $supplier->notes }}</dd></div>@endif
            </dl>
        </div>
    </div>

    @if ($orders)
        <section>
            <h3 class="mb-3 font-semibold">{{ __('Purchase history') }}</h3>
            <x-ui.table>
                <x-slot:head><th class="px-4 py-3">{{ __('Reference') }}</th><th class="px-4 py-3">{{ __('Date') }}</th><th class="px-4 py-3">{{ __('Branch') }}</th><th class="px-4 py-3 text-right">{{ __('Total') }}</th><th class="px-4 py-3">{{ __('Status') }}</th></x-slot:head>
                @forelse ($orders as $o)
                    <tr><td class="px-4 py-3"><a href="{{ route('purchasing.orders.show', $o) }}" class="font-medium text-brand-700 hover:underline dark:text-brand-100">{{ $o->reference }}</a></td><td class="px-4 py-3">{{ $o->order_date->format('d/m/Y') }}</td><td class="px-4 py-3">{{ $o->branch->name }}</td><td class="px-4 py-3 text-right tabular-nums">{{ \App\Support\Decimal::format($o->total) }}</td><td class="px-4 py-3"><x-ui.status :status="$o->status" /></td></tr>
                @empty
                    <x-ui.empty-row colspan="5">{{ __('No purchase orders yet.') }}</x-ui.empty-row>
                @endforelse
            </x-ui.table>
            <div class="mt-4">{{ $orders->links() }}</div>
        </section>
    @endif

    <section>
        <h3 class="mb-3 font-semibold">{{ __('Account entries') }}</h3>
        <x-ui.table>
            <x-slot:head><th class="px-4 py-3">{{ __('Date') }}</th><th class="px-4 py-3">{{ __('Reference') }}</th><th class="px-4 py-3">{{ __('Type') }}</th><th class="px-4 py-3 text-right">{{ __('Amount') }}</th></x-slot:head>
            @forelse ($transactions as $t)
                <tr><td class="px-4 py-3">{{ $t->occurred_at->timezone(config('saas.default_timezone'))->format('d/m/Y') }}</td><td class="px-4 py-3">{{ $t->reference }}</td><td class="px-4 py-3">{{ __(ucfirst(str_replace('_', ' ', $t->type))) }}</td><td class="px-4 py-3 text-right tabular-nums">{{ \App\Support\Decimal::format($t->amount) }}</td></tr>
            @empty
                <x-ui.empty-row colspan="4">{{ __('No entries yet.') }}</x-ui.empty-row>
            @endforelse
        </x-ui.table>
    </section>
</div>
