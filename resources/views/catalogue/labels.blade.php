<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Labels') }} · {{ $product->name }}</title>
    @fonts
    @vite(['resources/css/app.css'])
    <style>
        @page { margin: 8mm; }
        @media print { .no-print { display: none !important; } body { background: #fff; } }
        .label { break-inside: avoid; }
        .roll .label { width: 50mm; height: 30mm; page-break-after: always; }
    </style>
</head>
<body class="bg-slate-100 font-sans text-slate-900">
    <form method="GET" class="no-print mx-auto flex max-w-5xl flex-wrap items-end gap-3 p-4">
        <label class="text-sm">{{ __('Barcode') }}
            <select name="barcode" class="form-input">@foreach ($barcodes as $b) <option value="{{ $b->id }}" @selected($b->id === $barcode->id)>{{ $b->barcode }} ({{ __($b->productUnit->unit->name) }})</option> @endforeach</select>
        </label>
        <label class="text-sm">{{ __('Copies') }} <input type="number" name="copies" min="1" max="200" value="{{ $copies }}" class="form-input w-24"></label>
        <label class="text-sm">{{ __('Layout') }}
            <select name="layout" class="form-input" onchange="this.form.submit()">
                @foreach ($layouts as $l)
                    <option value="{{ $l }}" @selected($l === $layout)>{{ match ($l) {
                        'a4' => __('A4 sheet'), 'roll' => __('Label printer (50×30 mm)'),
                        'thermal-70x37' => __('Thermal 70×37 mm'), 'thermal-37x70' => __('Thermal 37×70 mm (rotated)'),
                    } }}</option>
                @endforeach
            </select>
        </label>
        <button type="submit" class="btn btn-secondary">{{ __('Update') }}</button>
        <button type="button" onclick="window.print()" class="btn btn-primary">{{ __('Print') }}</button>
    </form>

    <main @class(['mx-auto max-w-5xl bg-white p-4', 'grid grid-cols-3 gap-3' => $layout === 'a4', 'roll' => $layout === 'roll'])>
        @for ($i = 0; $i < $copies; $i++)
            <div class="label flex flex-col items-center justify-center rounded border border-dashed border-slate-300 p-2 text-center">
                <p class="w-full truncate text-xs font-semibold">{{ $product->name }}</p>
                <div class="my-1 w-full">{!! $svg !!}</div>
                <p class="font-mono text-[10px] tracking-widest">{{ $barcode->barcode }}</p>
                <p class="text-sm font-bold">{{ $currency }} {{ \App\Support\Decimal::format($price) }} <span class="text-[10px] font-normal">/ {{ $unit }}</span></p>
            </div>
        @endfor
    </main>
</body>
</html>
