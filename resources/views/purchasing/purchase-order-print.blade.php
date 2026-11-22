@use('App\Support\Decimal')
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $order->reference }} · {{ $company->name }}</title>
    @fonts
    @vite(['resources/css/app.css'])
    <style>@media print { .no-print { display: none !important; } body { background: #fff; } } @page { margin: 12mm; }</style>
</head>
<body class="bg-slate-100 font-sans text-slate-900">
    <div class="no-print mx-auto flex max-w-4xl justify-end gap-3 p-4">
        <a href="{{ route('purchasing.orders.show', $order) }}" class="btn btn-secondary">{{ __('Back') }}</a>
        <button type="button" onclick="window.print()" class="btn btn-primary">{{ __('Print') }}</button>
    </div>
    <main class="mx-auto max-w-4xl bg-white p-4 text-sm sm:p-8 print:p-0">
        <div class="flex flex-wrap justify-between gap-6 border-b border-slate-200 pb-6">
            <div><p class="text-xl font-bold">{{ $company->name }}</p><p class="text-slate-600">{{ $order->branch->name }}</p><p class="text-slate-600">{{ $company->address }}</p><p class="text-slate-600">{{ $company->phone }} · {{ $company->email }}</p></div>
            <div class="text-right"><p class="text-2xl font-bold">{{ __('PURCHASE ORDER') }}</p><p class="font-mono text-lg">{{ $order->reference }}</p><p>{{ __('Date') }}: {{ $order->order_date->format('d/m/Y') }}</p>@if ($order->expected_date)<p>{{ __('Expected delivery') }}: {{ $order->expected_date->format('d/m/Y') }}</p>@endif<p class="mt-1"><x-ui.status :status="$order->status" /></p></div>
        </div>
        <div class="grid gap-6 py-6 sm:grid-cols-2">
            <div><p class="text-xs font-semibold uppercase text-slate-500">{{ __('Supplier') }}</p><p class="font-semibold">{{ $order->supplier->name }}</p><p>{{ $order->supplier->contact_person }}</p><p>{{ $order->supplier->phone }} {{ $order->supplier->email }}</p><p>{{ $order->supplier->address }}</p>@if ($order->supplier->tax_id)<p>{{ __('TIN') }}: {{ $order->supplier->tax_id }}</p>@endif</div>
            <div><p class="text-xs font-semibold uppercase text-slate-500">{{ __('Deliver to') }}</p><p class="font-semibold">{{ $order->branch->name }}</p><p>{{ $order->branch->address }}</p></div>
        </div>
        <div class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0 print:overflow-visible"><table class="w-full min-w-[40rem] border-collapse print:min-w-0">
            <thead><tr class="border-b-2 border-slate-300 text-left text-xs uppercase text-slate-500"><th class="py-2">#</th><th class="py-2">{{ __('Product') }}</th><th class="py-2 text-right">{{ __('Quantity') }}</th><th class="py-2">{{ __('Unit') }}</th><th class="py-2 text-right">{{ __('Unit cost') }}</th><th class="py-2 text-right">{{ __('Discount') }}</th><th class="py-2 text-right">{{ __('Tax %') }}</th><th class="py-2 text-right">{{ __('Line total') }}</th></tr></thead>
            <tbody>
                @foreach ($order->lines as $i => $l)
                    <tr class="border-b border-slate-100"><td class="py-2">{{ $i + 1 }}</td><td class="py-2">{{ $l->product->name }}<span class="block text-xs text-slate-500">{{ $l->product->sku }}</span></td><td class="py-2 text-right tabular-nums">{{ Decimal::trim($l->quantity) }}</td><td class="py-2">{{ __($l->productUnit->unit->name) }}</td><td class="py-2 text-right tabular-nums">{{ Decimal::format($l->unit_cost) }}</td><td class="py-2 text-right tabular-nums">{{ Decimal::format($l->discount_amount) }}</td><td class="py-2 text-right">{{ Decimal::trim($l->tax_rate) }}</td><td class="py-2 text-right tabular-nums">{{ Decimal::format($l->line_total) }}</td></tr>
                @endforeach
            </tbody>
        </table></div>
        <dl class="ml-auto mt-6 w-full max-w-xs space-y-1">
            <div class="flex justify-between"><dt>{{ __('Subtotal') }}</dt><dd class="tabular-nums">{{ Decimal::format($order->subtotal) }}</dd></div>
            <div class="flex justify-between"><dt>{{ __('Discount') }}</dt><dd class="tabular-nums">−{{ Decimal::format($order->discount_total) }}</dd></div>
            <div class="flex justify-between"><dt>{{ __('Tax') }}</dt><dd class="tabular-nums">{{ Decimal::format($order->tax_total) }}</dd></div>
            <div class="flex justify-between border-t border-slate-300 pt-1 text-base font-bold"><dt>{{ __('Total') }}</dt><dd class="tabular-nums">{{ $order->currency }} {{ Decimal::format($order->total) }}</dd></div>
        </dl>
        @if ($order->notes)<p class="mt-6 rounded bg-slate-50 p-3">{{ $order->notes }}</p>@endif
        <div class="mt-12 grid grid-cols-2 gap-8 text-xs text-slate-500"><p class="border-t border-slate-300 pt-2">{{ __('Prepared by') }}: {{ $order->creator?->name }}</p><p class="border-t border-slate-300 pt-2">{{ __('Approved by') }}: {{ $order->approver?->name ?? '—' }}</p></div>
    </main>
</body>
</html>
