<?php

namespace App\Services\Catalogue;

use App\Enums\LocationType;
use App\Enums\PaymentMethodType;
use App\Enums\UnitDimension;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\StockLocation;
use App\Models\Unit;
use App\Support\NameKey;

/**
 * Idempotently creates a company's default units, categories and, for
 * each branch, its default stock locations. Safe to run repeatedly: only
 * MISSING defaults are inserted (matched by unit code, category name key
 * and location code), and nothing outside the given company is touched.
 */
class CompanyDefaultsProvisioner
{
    /** @return array{units: int, categories: int, locations: int, payment_methods: int} */
    public function provision(Company $company): array
    {
        $created = ['units' => 0, 'categories' => 0, 'locations' => 0];

        foreach (config('inventory.default_units') as $code => [$name, $dimension, $ratio]) {
            $unit = Unit::forCompany($company)->where('code', $code)->first() ?? (new Unit)->forceFill(['code' => $code]);

            if (! $unit->exists) {
                $unit->forceFill([
                    'company_id' => $company->id, 'name' => $name, 'dimension' => UnitDimension::from($dimension),
                    'ratio' => $ratio, 'is_system' => true, 'is_active' => true,
                ])->save();
                $created['units']++;
            }
        }

        foreach (config('inventory.default_categories') as $name) {
            $key = NameKey::make($name);
            $category = Category::forCompany($company)->where('name_key', $key)->first() ?? (new Category)->forceFill(['name_key' => $key]);

            if (! $category->exists) {
                $category->forceFill(['company_id' => $company->id, 'name' => $name, 'is_active' => true])->save();
                $created['categories']++;
            }
        }

        $created['payment_methods'] = $this->provisionPaymentMethods($company);

        foreach (Branch::withoutTenancy()->where('company_id', $company->id)->get() as $branch) {
            $created['locations'] += $this->provisionBranch($branch);
        }

        return $created;
    }

    public function provisionBranch(Branch $branch): int
    {
        $created = 0;

        foreach ([LocationType::MainStore, LocationType::SalesFloor, LocationType::ReceivingArea, LocationType::Quarantine] as $type) {
            $location = StockLocation::forCompany($branch->company_id)->where('branch_id', $branch->id)
                ->where('code', $type->defaultCode())->first() ?? (new StockLocation)->forceFill(['code' => $type->defaultCode()]);

            if (! $location->exists) {
                $location->forceFill([
                    'company_id' => $branch->company_id, 'branch_id' => $branch->id,
                    'name' => $type->label(), 'type' => $type, 'is_active' => true,
                    // Only the Sales Floor is sold from by default (never Receiving / Quarantine).
                    'is_sellable' => $type === LocationType::SalesFloor,
                ])->save();
                $created++;
            }
        }

        return $created;
    }

    /** Default, manually confirmed payment methods (idempotent by code). */
    public function provisionPaymentMethods(Company $company): int
    {
        $created = 0;
        $defaults = [
            ['cash', 'Cash', PaymentMethodType::Cash, false, 1],
            ['mobile_money', 'Mobile Money', PaymentMethodType::MobileMoney, true, 2],
            ['card', 'Bank / Card', PaymentMethodType::Card, false, 3],
            ['bank_transfer', 'Bank Transfer', PaymentMethodType::BankTransfer, true, 4],
            ['exchange_credit', 'Exchange credit', PaymentMethodType::ExchangeCredit, false, 9],
        ];

        foreach ($defaults as [$code, $name, $type, $reference, $sort]) {
            if (! PaymentMethod::forCompany($company)->where('code', $code)->exists()) {
                (new PaymentMethod)->forceFill([
                    'company_id' => $company->id, 'code' => $code, 'name' => $name, 'type' => $type,
                    'requires_reference' => $reference, 'is_active' => true, 'sort_order' => $sort,
                ])->save();
                $created++;
            }
        }

        return $created;
    }
}
