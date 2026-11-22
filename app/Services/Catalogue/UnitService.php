<?php

namespace App\Services\Catalogue;

use App\Enums\UnitDimension;
use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Product;
use App\Models\Unit;
use App\Support\Decimal;

class UnitService
{
    public function create(Company $company, string $name, string $code, UnitDimension $dimension, ?string $ratio): Unit
    {
        $code = strtolower(trim($code));

        if (! preg_match('/^[a-z0-9_\-]{1,20}$/', $code)) {
            throw BusinessRuleException::make('code', __('Use up to 20 lowercase letters, digits, - or _.'));
        }

        if (Unit::forCompany($company)->where('code', $code)->exists()) {
            throw BusinessRuleException::make('code', __('A unit with this code already exists.'));
        }

        if ($dimension->isMeasurement()) {
            if (! Decimal::isValid((string) $ratio) || ! Decimal::isPositive((string) $ratio)) {
                throw BusinessRuleException::make('ratio', __('Measurement units need a positive ratio to the reference unit (kg, l or m).'));
            }
            $ratio = Decimal::normalize($ratio, 9);
        } else {
            $ratio = null;
        }

        $unit = new Unit;
        $unit->forceFill([
            'company_id' => $company->id, 'name' => trim($name), 'code' => $code,
            'dimension' => $dimension, 'ratio' => $ratio, 'is_system' => false, 'is_active' => true,
        ])->save();

        return $unit;
    }

    public function rename(Unit $unit, string $name): void
    {
        $unit->forceFill(['name' => trim($name)])->save();
    }

    public function setActive(Unit $unit, bool $active): void
    {
        if (! $active && Product::forCompany($unit->company_id)->where('base_unit_id', $unit->id)->where('is_active', true)->exists()) {
            throw BusinessRuleException::make('unit', __('This unit is the base unit of active products.'));
        }

        $unit->forceFill(['is_active' => $active])->save();
    }
}
