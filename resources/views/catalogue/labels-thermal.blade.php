{{--
    Thermal sticker labels: 70 x 37 mm gap labels (e.g. ROMESON ZJ80UEB,
    74 mm media, 2 mm gap). Self-contained on purpose: no app CSS, so
    nothing but the rules below decides the printed size.

    - @page is the sticker itself (70mm 37mm, or 37mm 70mm when rotated)
      with margin 0: one label per printed page, no A4, no browser scaling,
      and no room for the browser's header/footer (URL, date, title).
    - Safe content box 66 x 34 mm (2 mm / 1.5 mm inner padding), centred.
    - Barcode at an exact physical size (module = {{ $moduleMm }} mm = 4
      dots on a 203 dpi head, 12 mm bars, 11-module quiet zones).
    - On screen every label is shown at its real size (CSS mm), one per copy.
--}}
@use('App\Support\Decimal')
@php
    $name = $product->name;
    // thermal-friendly sizes: short names bigger, long names smaller but never cut to "ACT..."
    $len = mb_strlen($name);
    $nameSize = $len > 60 ? '7.5pt' : ($len > 34 ? '8.5pt' : ($len > 22 ? '10pt' : '12pt'));
    $nameLines = $len > 60 ? 3 : 2;
    $pageW = $rotated ? '37mm' : '70mm';
    $pageH = $rotated ? '70mm' : '37mm';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- the title is the only page text a browser could add; keep it short --}}
    <title>{{ $product->name }}</title>
    <style>
        @page { size: {{ $pageW }} {{ $pageH }}; margin: 0; }

        *, *::before, *::after { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body { font-family: Arial, "Helvetica Neue", Helvetica, sans-serif; color: #000; background: #e5e7eb;
               -webkit-print-color-adjust: exact; print-color-adjust: exact; }

        /* ---- one sticker = one page ---- */
        .label { position: relative; width: {{ $pageW }}; height: {{ $pageH }}; overflow: hidden; background: #fff; }
        .label-inner {
            position: absolute; top: 0; left: 0; width: 70mm; height: 37mm;
            padding: 1.5mm 2mm;                     /* safe content box: 66 x 34 mm */
            display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.6mm;
            text-align: center;
        }
        .rotated .label-inner { left: 37mm; transform: rotate(90deg); transform-origin: top left; }

        .name { width: 100%; margin: 0; font-weight: 700; font-size: {{ $nameSize }}; line-height: 1.15;
                overflow-wrap: anywhere; display: -webkit-box; -webkit-box-orient: vertical;
                -webkit-line-clamp: {{ $nameLines }}; line-clamp: {{ $nameLines }}; overflow: hidden; }
        .barcode { line-height: 0; }
        .barcode svg { display: block; }
        .digits { margin: 0; font-family: "DejaVu Sans Mono", "Courier New", monospace; font-size: 8.5pt; letter-spacing: 0.6mm; line-height: 1; }
        .price { margin: 0; line-height: 1; white-space: nowrap; }
        .price .amount { font-weight: 700; font-size: 14pt; }
        .price .unit { font-size: 8pt; font-weight: 400; margin-left: 1mm; }

        /* ---- screen: real-size preview, one sticker per copy ---- */
        @media screen {
            .toolbar { position: sticky; top: 0; z-index: 1; background: #fff; border-bottom: 1px solid #cbd5e1; padding: 12px 16px;
                       display: flex; flex-wrap: wrap; gap: 10px 14px; align-items: end; font: 14px/1.4 Arial, sans-serif; }
            .toolbar label { display: flex; flex-direction: column; gap: 4px; font-size: 13px; color: #334155; }
            .toolbar select, .toolbar input { height: 40px; border: 1px solid #94a3b8; border-radius: 8px; padding: 0 8px; font-size: 14px; background: #fff; }
            .toolbar button { height: 40px; border-radius: 8px; padding: 0 16px; font-weight: 700; font-size: 14px; cursor: pointer; border: 1px solid #94a3b8; background: #fff; }
            .toolbar .primary { background: #059669; border-color: #059669; color: #fff; }
            .toolbar :focus-visible { outline: 3px solid #10b981; outline-offset: 2px; }
            .help { flex-basis: 100%; margin: 0; font-size: 12.5px; color: #475569; }
            .help strong { color: #0f172a; }
            .sheet { display: flex; flex-direction: column; align-items: center; gap: 6mm; padding: 8mm 4mm 16mm; }
            .label { box-shadow: 0 0 0 1px #94a3b8, 0 6px 18px rgba(15, 23, 42, .15); border-radius: 1.5mm; }
            .caption { font: 12px Arial, sans-serif; color: #64748b; margin: -4mm 0 0; }
        }

        /* ---- print: only the stickers, back to back, one per page ---- */
        @media print {
            html, body { width: {{ $pageW }}; background: #fff; }
            .toolbar, .caption { display: none !important; }
            .sheet { display: block; margin: 0; padding: 0; }
            .label { margin: 0; box-shadow: none; border-radius: 0; break-after: page; page-break-after: always; break-inside: avoid; }
            .label:last-of-type { break-after: auto; page-break-after: auto; }
        }
    </style>
</head>
<body>
    <form method="GET" class="toolbar" data-label-toolbar>
        <label>{{ __('Barcode') }}
            <select name="barcode">@foreach ($barcodes as $b) <option value="{{ $b->id }}" @selected($b->id === $barcode->id)>{{ $b->barcode }} ({{ __($b->productUnit->unit->name) }})</option> @endforeach</select>
        </label>
        <label>{{ __('Copies') }} <input type="number" name="copies" min="1" max="200" value="{{ $copies }}" style="width: 90px"></label>
        <label>{{ __('Layout') }}
            <select name="layout" onchange="this.form.submit()">
                @foreach ($layouts as $l)
                    <option value="{{ $l }}" @selected($l === $layout)>{{ match ($l) {
                        'a4' => __('A4 sheet'), 'roll' => __('Label printer (50×30 mm)'),
                        'thermal-70x37' => __('Thermal 70×37 mm'), 'thermal-37x70' => __('Thermal 37×70 mm (rotated)'),
                    } }}</option>
                @endforeach
            </select>
        </label>
        <button type="submit">{{ __('Update') }}</button>
        <button type="button" class="primary" onclick="window.print()" data-print>{{ __('Print') }}</button>
        <p class="help">{{ __('In the print dialog choose:') }}
            <strong>{{ __('your label printer') }}</strong> ·
            {{ __('Paper size') }} <strong>{{ $rotated ? '37 × 70 mm' : '70 × 37 mm' }}</strong> ·
            {{ __('Margins') }} <strong>{{ __('None') }}</strong> ·
            {{ __('Scale') }} <strong>100%</strong> ·
            {{ __('Headers and footers') }} <strong>{{ __('off') }}</strong>.
            {{ __('If the label comes out sideways, switch the layout to') }} <strong>{{ $rotated ? __('Thermal 70×37 mm') : __('Thermal 37×70 mm (rotated)') }}</strong>.</p>
    </form>

    <main class="sheet {{ $rotated ? 'rotated' : '' }}" data-thermal-sheet data-page="{{ $pageW }}x{{ $pageH }}">
        @for ($i = 0; $i < $copies; $i++)
            <section class="label" data-label>
                <div class="label-inner">
                    <p class="name" data-label-name>{{ $name }}</p>
                    <div class="barcode">{!! $svg !!}</div>
                    <p class="digits">{{ $barcode->barcode }}</p>
                    <p class="price"><span class="amount">{{ $currency }} {{ Decimal::format($price) }}</span><span class="unit">/ {{ $unit }}</span></p>
                </div>
            </section>
            @if ($i === 0)<p class="caption">{{ __('Actual size') }}: {{ $rotated ? '37 × 70 mm' : '70 × 37 mm' }}@if ($copies > 1) · {{ __(':n copies', ['n' => $copies]) }}@endif</p>@endif
        @endfor
    </main>

    @if ($autoprint)
        <script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
    @endif
</body>
</html>
