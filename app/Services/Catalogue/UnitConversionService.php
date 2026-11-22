<?php

namespace App\Services\Catalogue;

use App\Enums\UnitDimension;
use App\Exceptions\InvalidUnitConversionException;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Support\Decimal;

/**
 * Product-specific unit conversions. Stock is always held in the
 * product's BASE unit; every other unit is "1 unit = factor x base".
 *
 * Rules:
 * - factor > 0, at most 6 decimal places
 * - measurement units of the SAME dimension as the base convert by their
 *   physical ratio (1 kg = 1000 g); a different factor is rejected
 * - measurement units of a DIFFERENT dimension are incompatible (kg vs l)
 * - count/packaging units (pack, carton, ...) take a product-specific
 *   factor; on a count base unit the factor must be a whole number
 * - a count/packaging unit on a measured product is packaging with its own
 *   factor (e.g. 1 Bag = 25 kg of rice)
 */
class UnitConversionService
{
    /**
     * Validate (and where physically determined, derive) the factor.
     *
     * @return string Normalised factor (scale 6)
     */
    public function resolveFactor(Product $product, Unit $unit, ?string $factor): string
    {
        $base = $product->baseUnit;

        if ($unit->company_id !== $product->company_id || ! $unit->is_active) {
            throw InvalidUnitConversionException::make('unit', __('Select a valid unit.'));
        }

        if ($unit->id === $base->id) {
            return '1.000000';
        }

        if ($unit->dimension->isMeasurement() && $base->dimension->isMeasurement()) {
            if ($unit->dimension !== $base->dimension) {
                throw InvalidUnitConversionException::make('factor', __(':unit (:a) cannot be converted to :base (:b).', [
                    'unit' => $unit->name, 'a' => __($unit->dimension->label()),
                    'base' => $base->name, 'b' => __($base->dimension->label()),
                ]));
            }

            $physical = Decimal::round(Decimal::div($unit->ratio, $base->ratio), Decimal::FACTOR);

            if ($factor !== null && $factor !== '' && Decimal::cmp($this->normalizeFactor($factor), $physical) !== 0) {
                throw InvalidUnitConversionException::make('factor', __('1 :unit is always :factor :base.', [
                    'unit' => $unit->name, 'factor' => Decimal::trim($physical), 'base' => $base->name,
                ]));
            }

            return $physical;
        }

        if ($unit->dimension->isMeasurement() && ! $base->dimension->isMeasurement()) {
            throw InvalidUnitConversionException::make('factor', __(':unit is a measurement unit and cannot be used for a product counted in :base.', [
                'unit' => $unit->name, 'base' => $base->name,
            ]));
        }

        // Count/packaging unit: product-specific factor is mandatory.
        if ($factor === null || $factor === '') {
            throw InvalidUnitConversionException::make('factor', __('Enter how many :base are in one :unit.', [
                'base' => $base->name, 'unit' => $unit->name,
            ]));
        }

        $factor = $this->normalizeFactor($factor);

        if (Decimal::cmp($factor, '1') === 0) {
            throw InvalidUnitConversionException::make('factor', __('A packaging unit must contain more or less than one base unit.'));
        }

        if ($base->dimension === UnitDimension::Count && ! Decimal::isWhole($factor)) {
            throw InvalidUnitConversionException::make('factor', __('Packaging of a counted product must contain a whole number of :base.', ['base' => $base->name]));
        }

        return $factor;
    }

    public function normalizeFactor(string $factor): string
    {
        if (! Decimal::isValid($factor)) {
            throw InvalidUnitConversionException::make('factor', __('The conversion factor must be a number.'));
        }

        if (Decimal::scaleOf($factor) > Decimal::FACTOR) {
            throw InvalidUnitConversionException::make('factor', __('The conversion factor may have at most 6 decimal places.'));
        }

        $factor = Decimal::normalize($factor, Decimal::FACTOR);

        if (! Decimal::isPositive($factor)) {
            throw InvalidUnitConversionException::make('factor', __('The conversion factor must be greater than zero.'));
        }

        if (Decimal::cmp($factor, '1000000') > 0) {
            throw InvalidUnitConversionException::make('factor', __('The conversion factor is too large.'));
        }

        return $factor;
    }

    /**
     * Convert an entered quantity to base units.
     *
     * @return string Base quantity (scale 4)
     */
    public function toBase(Product $product, ProductUnit $productUnit, string $quantity, string $field = 'quantity'): string
    {
        if ($productUnit->product_id !== $product->id || ! $productUnit->is_active) {
            throw InvalidUnitConversionException::make($field, __('Select a valid unit for :product.', ['product' => $product->name]));
        }

        if (! Decimal::isValid($quantity) || Decimal::scaleOf($quantity) > Decimal::QTY) {
            throw InvalidUnitConversionException::make($field, __('Enter a quantity with at most 4 decimal places.'));
        }

        $quantity = Decimal::normalize($quantity, Decimal::QTY);

        if (! Decimal::isPositive($quantity)) {
            throw InvalidUnitConversionException::make($field, __('The quantity must be greater than zero.'));
        }

        $base = Decimal::mul($quantity, $productUnit->factor);

        if (Decimal::scaleOf($base) > Decimal::QTY) {
            throw InvalidUnitConversionException::make($field, __('This quantity cannot be represented exactly in :base.', ['base' => $product->baseUnit->name]));
        }

        if ($product->product_type->requiresWholeBaseQuantities() && ! Decimal::isWhole($base)) {
            throw InvalidUnitConversionException::make($field, __(':product is counted in whole :base.', [
                'product' => $product->name, 'base' => $product->baseUnit->name,
            ]));
        }

        return Decimal::round($base, Decimal::QTY);
    }

    /** Add a unit to a product (or update its factor). */
    public function setUnit(Product $product, Unit $unit, ?string $factor, bool $canPurchase = true, bool $canSell = true): ProductUnit
    {
        $factor = $this->resolveFactor($product, $unit, $factor);

        $productUnit = ProductUnit::forCompany($product->company_id)
            ->where('product_id', $product->id)->where('unit_id', $unit->id)->first() ?? new ProductUnit;

        if ($productUnit->exists && $productUnit->is_base) {
            throw InvalidUnitConversionException::make('unit', __('The base unit conversion is always 1.'));
        }

        // Changing a factor affects FUTURE documents only: every document line
        // and stock movement stores its own conversion_factor snapshot.
        $productUnit->forceFill([
            'company_id' => $product->company_id,
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'factor' => $factor,
            'is_base' => false,
            'can_purchase' => $canPurchase,
            'can_sell' => $canSell,
            'is_active' => true,
        ])->save();

        return $productUnit;
    }

    public function deactivate(ProductUnit $productUnit): void
    {
        if ($productUnit->is_base) {
            throw InvalidUnitConversionException::make('unit', __('The base unit cannot be removed.'));
        }

        $productUnit->forceFill(['is_active' => false])->save();
    }
}
