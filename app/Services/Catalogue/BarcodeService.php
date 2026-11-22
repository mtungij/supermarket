<?php

namespace App\Services\Catalogue;

use App\Enums\BarcodeType;
use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Services\Support\DocumentNumberGenerator;
use App\Support\Barcode\Gtin;
use Illuminate\Support\Facades\DB;

/**
 * Barcode rules (see docs/product-catalogue.md):
 * - A barcode is unique within a company and maps to exactly one
 *   product + unit (base unit or a packaging unit such as a carton).
 * - Manufacturer and packaging GTINs must pass the GS1 check digit.
 * - Internal barcodes are EAN-13 in the restricted-circulation range
 *   (prefix 20), generated from a per-company sequence.
 * - One barcode per product may be primary (printed on shelf labels).
 */
class BarcodeService
{
    public function __construct(private readonly DocumentNumberGenerator $numbers) {}

    public static function normalize(string $code): string
    {
        return strtoupper(preg_replace('/\s+/', '', $code) ?? '');
    }

    public function validateFormat(string $code, BarcodeType $type, string $field = 'barcode'): string
    {
        $code = self::normalize($code);

        if ($code === '' || strlen($code) > 64 || ! preg_match('/^[A-Z0-9\-\.]+$/', $code)) {
            throw BusinessRuleException::make($field, __('Barcodes may contain only letters, digits, dashes and dots (max 64).'));
        }

        if (in_array($type, [BarcodeType::Manufacturer, BarcodeType::Packaging], true)
            && Gtin::isGtin($code) && ! Gtin::isValid($code)) {
            throw BusinessRuleException::make($field, __('The barcode check digit is invalid. Scan it again or check for typing errors.'));
        }

        return $code;
    }

    public function add(Product $product, string $code, BarcodeType $type, ?ProductUnit $productUnit = null, bool $primary = false, string $field = 'barcode'): ProductBarcode
    {
        $code = $this->validateFormat($code, $type, $field);
        $productUnit ??= $product->baseProductUnit;

        if ($productUnit === null || $productUnit->product_id !== $product->id) {
            throw BusinessRuleException::make($field, __('Select a valid unit for this barcode.'));
        }

        if ($type === BarcodeType::Packaging && $productUnit->is_base) {
            throw BusinessRuleException::make($field, __('Packaging barcodes must be linked to a packaging unit.'));
        }

        return DB::transaction(function () use ($product, $code, $type, $productUnit, $primary, $field) {
            $existing = ProductBarcode::forCompany($product->company_id)->where('barcode', $code)->with('product:id,name')->first();

            if ($existing !== null) {
                throw BusinessRuleException::make($field, __('Barcode :code is already assigned to :product.', [
                    'code' => $code, 'product' => $existing->product->name,
                ]));
            }

            $makePrimary = $primary || ! ProductBarcode::forCompany($product->company_id)
                ->where('product_id', $product->id)->where('is_primary', true)->exists();

            if ($makePrimary) {
                ProductBarcode::forCompany($product->company_id)->where('product_id', $product->id)->update(['is_primary' => false]);
            }

            $barcode = new ProductBarcode;
            $barcode->forceFill([
                'company_id' => $product->company_id,
                'product_id' => $product->id,
                'product_unit_id' => $productUnit->id,
                'barcode' => $code,
                'type' => $type,
                'is_primary' => $makePrimary,
            ])->save();

            return $barcode;
        });
    }

    public function generateInternal(Product $product, ?ProductUnit $productUnit = null): ProductBarcode
    {
        return DB::transaction(function () use ($product, $productUnit) {
            $company = Company::query()->findOrFail($product->company_id);

            do {
                $sequence = $this->numbers->nextNumber($company, DocumentNumberGenerator::INTERNAL_BARCODE);
                $payload = config('inventory.internal_barcode_prefix').str_pad((string) $sequence, 10, '0', STR_PAD_LEFT);
                $code = $payload.Gtin::checkDigit($payload);
            } while (ProductBarcode::forCompany($company)->where('barcode', $code)->exists());

            return $this->add($product, $code, BarcodeType::Internal, $productUnit);
        });
    }

    public function makePrimary(ProductBarcode $barcode): void
    {
        DB::transaction(function () use ($barcode) {
            ProductBarcode::forCompany($barcode->company_id)->where('product_id', $barcode->product_id)->update(['is_primary' => false]);
            ProductBarcode::forCompany($barcode->company_id)->whereKey($barcode->id)->update(['is_primary' => true]);
        });
    }

    public function remove(ProductBarcode $barcode): void
    {
        DB::transaction(function () use ($barcode) {
            $barcode->delete();

            if ($barcode->is_primary) {
                $next = ProductBarcode::forCompany($barcode->company_id)->where('product_id', $barcode->product_id)->orderBy('id')->first();
                $next?->forceFill(['is_primary' => true])->save();
            }
        });
    }

    /**
     * Scanner lookup (future POS entry point): exact barcode first, then SKU.
     * Returns the barcode record with its product and unit, or null.
     *
     * @return array{product: Product, product_unit: ProductUnit, barcode: ?ProductBarcode}|null
     */
    public function lookup(Company $company, string $code): ?array
    {
        $code = self::normalize($code);

        if ($code === '') {
            return null;
        }

        $barcode = ProductBarcode::forCompany($company)
            ->where('barcode', $code)
            ->with(['product.baseUnit', 'productUnit.unit'])
            ->first();

        if ($barcode !== null) {
            return ['product' => $barcode->product, 'product_unit' => $barcode->productUnit, 'barcode' => $barcode];
        }

        $product = Product::forCompany($company)->where('sku', $code)->with('baseUnit')->first();

        return $product === null ? null : [
            'product' => $product,
            'product_unit' => $product->baseProductUnit()->with('unit')->first(),
            'barcode' => null,
        ];
    }
}
