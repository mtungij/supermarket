<?php

namespace App\Services\Inventory;

use App\Enums\LocationType;
use App\Exceptions\BusinessRuleException;
use App\Models\Branch;
use App\Models\StockBalance;
use App\Models\StockLocation;

class StockLocationService
{
    public function create(Branch $branch, string $name, string $code, LocationType $type): StockLocation
    {
        $code = strtoupper(trim($code));

        if (! preg_match('/^[A-Z0-9_\-]{1,20}$/', $code)) {
            throw BusinessRuleException::make('code', __('Use up to 20 letters, digits, - or _.'));
        }

        if (StockLocation::forCompany($branch->company_id)->where('branch_id', $branch->id)->where('code', $code)->exists()) {
            throw BusinessRuleException::make('code', __('This branch already has a location with that code.'));
        }

        $location = new StockLocation(['name' => trim($name), 'code' => $code, 'is_active' => true]);
        $location->forceFill(['company_id' => $branch->company_id, 'branch_id' => $branch->id, 'type' => $type])->save();

        return $location;
    }

    public function setActive(StockLocation $location, bool $active): void
    {
        if (! $active && StockBalance::forCompany($location->company_id)->where('location_id', $location->id)->where('quantity', '!=', 0)->exists()) {
            throw BusinessRuleException::make('location', __('Move the stock out of this location before deactivating it.'));
        }

        $location->forceFill(['is_active' => $active])->save();
    }

    /** Explicitly allow / stop POS selling from a location. Quarantine can never be sellable. */
    public function setSellable(StockLocation $location, bool $sellable): void
    {
        if ($sellable && ! $location->type->canBeSellable()) {
            throw BusinessRuleException::make('location', __('Damaged / quarantine stock can never be sold.'));
        }

        $location->forceFill(['is_sellable' => $sellable])->save();
    }
}
