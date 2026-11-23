<?php

namespace App\Services\Inventory;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockLocation;
use App\Services\Catalogue\BarcodeService;
use App\Support\Decimal;
use App\Support\SpreadsheetReader;
use Illuminate\Validation\ValidationException;

/**
 * Turns spreadsheet rows into opening-stock lines. Products are matched by
 * SKU or barcode (tenant-scoped BarcodeService, so a packaging barcode
 * selects its carton/pack unit), then EVERY row goes through
 * OpeningStockService::prepareLines() - the same rules as manual entry.
 * The result is all-or-nothing: a draft is only created when no row has an
 * error, and posting uses the same posting service.
 */
class OpeningStockImport
{
    public const FIELDS = [
        'sku' => ['sku', 'code', 'product code', 'item code', 'product_sku'],
        'barcode' => ['barcode', 'ean', 'upc', 'gtin', 'bar code'],
        'name' => ['product_name', 'product name', 'name', 'product', 'item', 'description', 'jina', 'jina la bidhaa'],
        'unit' => ['unit', 'uom', 'unit of measure', 'kipimo'],
        'quantity' => ['quantity', 'qty', 'opening quantity', 'stock', 'on hand', 'idadi'],
        'unit_cost' => ['unit_cost', 'unit cost', 'cost', 'cost price', 'buying price', 'gharama'],
        'batch_number' => ['batch_number', 'batch number', 'batch', 'lot', 'lot number'],
        'manufactured_on' => ['manufactured_on', 'manufactured', 'manufacture date', 'mfg', 'mfg date'],
        'expires_on' => ['expires_on', 'expiry date', 'expiry', 'expires', 'exp', 'exp date', 'best before'],
    ];

    public function __construct(
        private readonly OpeningStockService $service,
        private readonly BarcodeService $barcodes,
    ) {}

    /**
     * Header row -> field => column index ('' when not found).
     *
     * @param  list<string>  $headers
     * @return array<string, string>
     */
    public function autoMap(array $headers): array
    {
        $normal = array_map(fn ($h) => strtolower(trim(str_replace(['*', '_'], ['', ' '], $h))), $headers);
        $map = [];
        foreach (self::FIELDS as $field => $names) {
            $map[$field] = '';
            foreach ($names as $name) {
                $i = array_search(str_replace('_', ' ', $name), $normal, true);
                if ($i !== false) {
                    $map[$field] = (string) $i;
                    break;
                }
            }
        }

        return $map;
    }

    /**
     * @param  list<list<string>>  $rows  data rows (no header)
     * @param  array<string, string>  $map  field => column index
     * @return array{lines: list<array>, preview: list<array>, errors: array<int, list<string>>, skipped: int,
     *     totals: array{rows: int, products: int, quantity: string, value: string, zero_cost_rows: int}}
     */
    public function analyse(Company $company, StockLocation $location, array $rows, array $map, bool $confirmZeroCost): array
    {
        $col = fn (array $row, string $field) => ($map[$field] ?? '') === '' ? '' : trim((string) ($row[(int) $map[$field]] ?? ''));
        $errors = [];
        $candidates = [];
        $preview = [];
        $skipped = 0;
        $zeroRows = 0;

        if (($map['quantity'] ?? '') === '' || ($map['unit_cost'] ?? '') === '' || (($map['sku'] ?? '') === '' && ($map['barcode'] ?? '') === '')) {
            throw ValidationException::withMessages(['mapping' => __('Map at least the SKU or Barcode column, plus Quantity and Unit cost.')]);
        }

        $skus = Product::forCompany($company)->whereIn('sku', array_filter(array_map(fn ($r) => strtoupper($col($r, 'sku')), $rows)))
            ->get()->keyBy('sku');

        foreach ($rows as $r => $row) {
            $rowNumber = $r + 2;                      // spreadsheet row (header is row 1)
            $qty = str_replace([',', ' '], '', $col($row, 'quantity'));
            $cost = str_replace([',', ' '], '', $col($row, 'unit_cost'));

            if ($qty === '' && $cost === '') {
                $skipped++;                            // template rows for products not in stock

                continue;
            }

            $sku = strtoupper($col($row, 'sku'));
            $code = $col($row, 'barcode');
            $product = null;
            $unit = null;

            if ($sku !== '') {
                $product = $skus[$sku] ?? null;
                if ($product === null) {
                    $errors[$rowNumber][] = __('No product with SKU :sku.', ['sku' => $sku]);

                    continue;
                }
            }
            if ($code !== '') {
                $match = $this->barcodes->lookup($company, $code);
                if ($match === null) {
                    $errors[$rowNumber][] = __('No product with barcode :code.', ['code' => $code]);

                    continue;
                }
                if ($product !== null && $match['product']->id !== $product->id) {
                    $errors[$rowNumber][] = __('SKU :sku and barcode :code belong to different products.', ['sku' => $sku, 'code' => $code]);

                    continue;
                }
                $product ??= $match['product'];
                $unit = $match['product_unit'];
            }
            if ($product === null) {
                $errors[$rowNumber][] = __('Enter the SKU or barcode of the product.');

                continue;
            }

            $unitText = mb_strtolower($col($row, 'unit'));
            if ($unitText !== '') {
                $unit = ProductUnit::forCompany($company)->where('product_id', $product->id)->where('is_active', true)->with('unit')->get()
                    ->first(fn ($u) => in_array($unitText, [mb_strtolower($u->unit->code), mb_strtolower($u->unit->name), mb_strtolower(__($u->unit->name))], true));
                if ($unit === null) {
                    $errors[$rowNumber][] = __('":unit" is not a unit of :product.', ['unit' => $col($row, 'unit'), 'product' => $product->name]);

                    continue;
                }
            }
            $unit ??= ProductUnit::forCompany($company)->where('product_id', $product->id)->where('is_base', true)->first();

            $made = SpreadsheetReader::date($col($row, 'manufactured_on'));
            $expires = SpreadsheetReader::date($col($row, 'expires_on'));
            if ($made === null || $expires === null) {
                $errors[$rowNumber][] = __('Dates must look like 2026-12-31 or 31/12/2026.');

                continue;
            }

            if (Decimal::isValid($cost) && ! Decimal::isPositive($cost)) {
                $zeroRows++;
            }

            $candidates[] = [
                'product_id' => $product->id, 'product_unit_id' => $unit->id, 'quantity' => $qty, 'unit_cost' => $cost,
                'zero_cost_confirmed' => $confirmZeroCost, 'batch_number' => $col($row, 'batch_number'),
                'manufactured_on' => $made, 'expires_on' => $expires, 'import_row' => $rowNumber,
                '_name' => $product->name, '_unit' => $unit->unit?->name,
            ];
        }

        $lines = [];
        if ($candidates !== []) {
            try {
                $lines = $this->service->prepareLines($company, $location, $candidates);
            } catch (ValidationException $e) {
                foreach ($e->errors() as $key => $messages) {
                    if (preg_match('/^lines\.(\d+)\./', $key, $m)) {
                        $errors[$candidates[(int) $m[1]]['import_row']][] = $messages[0];
                    } else {
                        $errors[0][] = $messages[0];
                    }
                }
            }
        } elseif ($errors === []) {
            $errors[0][] = __('The file has no rows with a quantity.');
        }
        ksort($errors);

        foreach (array_slice($candidates, 0, 200) as $c) {
            $preview[] = ['row' => $c['import_row'], 'name' => $c['_name'], 'unit' => $c['_unit'], 'quantity' => $c['quantity'],
                'unit_cost' => $c['unit_cost'], 'batch' => $c['batch_number'], 'expires_on' => $c['expires_on'],
                'error' => $errors[$c['import_row']][0] ?? null];
        }

        return [
            'lines' => $errors === [] ? $lines : [],
            'preview' => $preview,
            'errors' => $errors,
            'skipped' => $skipped,
            'totals' => [
                'rows' => count($candidates),
                'products' => count(array_unique(array_column($candidates, 'product_id'))),
                'quantity' => array_reduce($lines, fn ($c, $l) => Decimal::add($c, $l['quantity_base'], Decimal::QTY), '0'),
                'value' => array_reduce($lines, fn ($c, $l) => Decimal::add($c, $l['line_value'], Decimal::COST), '0'),
                'zero_cost_rows' => $zeroRows,
            ],
        ];
    }
}
