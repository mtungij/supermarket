@use('App\Support\Decimal')
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ __('Receipt') }} {{ $sale->sale_number }}</title>
    <style>
        /* Thermal receipt: always light, fixed roll width, continuous height. */
        @page { size: {{ $width }}mm auto; margin: 0; }
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: #fff; color: #000; }
        body { font: {{ $width === 58 ? '11px' : '12px' }}/1.35 ui-monospace, "DejaVu Sans Mono", Menlo, Consolas, monospace; }
        .receipt { width: {{ $width }}mm; padding: 3mm {{ $width === 58 ? '2mm' : '3mm' }}; margin: 0 auto; overflow-wrap: anywhere; word-break: break-word; }
        .center { text-align: center; }
        .bold { font-weight: 700; }
        .big { font-size: 1.35em; }
        .row { display: flex; justify-content: space-between; gap: 2mm; }
        .row > :last-child { text-align: right; white-space: nowrap; }
        .rule { border-top: 1px dashed #000; margin: 2mm 0; }
        .item { margin: 1mm 0; }
        .muted { font-size: .9em; }
        .toolbar { font: 14px system-ui, sans-serif; display: flex; gap: 8px; justify-content: center; padding: 12px; background: #f1f5f9; }
        .toolbar a, .toolbar button { padding: 8px 14px; border: 1px solid #94a3b8; border-radius: 8px; background: #fff; color: #000; text-decoration: none; cursor: pointer; }
        @media print { .toolbar { display: none !important; } .receipt { padding-top: 0; } }
    </style>
</head>
<body>
    <div class="toolbar">
        <a href="?width=58">58 mm</a>
        <a href="?width=80">80 mm</a>
        <button type="button" onclick="window.print()">{{ __('Print') }}</button>
    </div>
    <div class="receipt">
        <div class="center">
            <div class="bold big">{{ $company->name }}</div>
            <div>{{ $sale->branch->name }}</div>
            @if ($sale->branch->address ?: $company->address)<div class="muted">{{ $sale->branch->address ?: $company->address }}</div>@endif
            <div class="muted">{{ collect([$sale->branch->phone ?: $company->phone, $company->email])->filter()->join(' · ') }}</div>
        </div>
        <div class="rule"></div>
        <div class="row"><span>{{ __('Sale') }}</span><span class="bold">{{ $sale->sale_number }}</span></div>
        <div class="row"><span>{{ __('Date') }}</span><span>{{ $sale->sold_at->timezone($tz)->format('d/m/Y H:i') }}</span></div>
        <div class="row"><span>{{ __('Cashier') }}</span><span>{{ $sale->cashier->name }}</span></div>
        @if ($sale->device)<div class="row"><span>{{ __('Register') }}</span><span>{{ $sale->device->name }}</span></div>@endif
        @if ($sale->customer)<div class="row"><span>{{ __('Customer') }}</span><span>{{ $sale->customer->name }}</span></div>@endif
        <div class="rule"></div>
        @foreach ($sale->items as $item)
            <div class="item">
                <div>{{ $item->product_name }}</div>
                <div class="row"><span>{{ Decimal::trim($item->quantity) }} {{ __($item->unit_name) }} × {{ Decimal::format($item->unit_price) }}</span><span>{{ Decimal::format(Decimal::sub((string) $item->line_total, '0')) }}</span></div>
                @if (Decimal::isPositive((string) $item->discount_amount))<div class="row muted"><span>{{ __('Discount') }}</span><span>−{{ Decimal::format($item->discount_amount) }}</span></div>@endif
            </div>
        @endforeach
        <div class="rule"></div>
        <div class="row"><span>{{ __('Subtotal') }}</span><span>{{ Decimal::format($sale->subtotal) }}</span></div>
        @if (Decimal::isPositive((string) $sale->discount_total))<div class="row"><span>{{ __('Discount') }}</span><span>−{{ Decimal::format($sale->discount_total) }}</span></div>@endif
        @if (Decimal::isPositive((string) $sale->tax_total))<div class="row"><span>{{ $sale->prices_include_tax ? __('VAT (included)') : __('VAT') }}</span><span>{{ Decimal::format($sale->tax_total) }}</span></div>@endif
        <div class="row bold big"><span>{{ __('TOTAL') }}</span><span>{{ $sale->currency }} {{ Decimal::format($sale->total) }}</span></div>
        <div class="rule"></div>
        @foreach ($sale->payments as $p)
            <div class="row"><span>{{ __($p->method_name) }}@if ($p->reference) ({{ $p->reference }})@endif</span><span>{{ Decimal::format($p->tendered) }}</span></div>
        @endforeach
        <div class="row"><span>{{ __('Amount received') }}</span><span>{{ Decimal::format($sale->tendered_total) }}</span></div>
        <div class="row bold"><span>{{ __('Change') }}</span><span>{{ Decimal::format($sale->change_total) }}</span></div>
        <div class="rule"></div>
        <div class="center">{{ $footer }}</div>
        <div class="center muted">{{ $sale->sale_number }}</div>
    </div>
    @if ($autoprint)
        <script>window.addEventListener('load', () => { window.focus(); window.print(); });</script>
    @endif
</body>
</html>
