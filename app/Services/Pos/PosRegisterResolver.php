<?php

namespace App\Services\Pos;

use App\Enums\LocationType;
use App\Enums\PosDeviceStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\PosDevice;
use App\Models\StockLocation;
use Illuminate\Support\Collection;

/**
 * Which register a cashier sells on and which location stock leaves from.
 *
 * - Selling location = the device's configured location, or else the
 *   branch's default selling location (first active location flagged
 *   `is_sellable`, Sales Floor first). Receiving Area / Main Store are
 *   never used unless an authorised user explicitly marks them sellable.
 * - There is NO fallback to "any location with stock".
 */
class PosRegisterResolver
{
    /** Company setting; registered devices are required by default. */
    public function requiresDevice(Company $company): bool
    {
        return (bool) data_get($company->settings, 'pos_require_device', true);
    }

    /** @return Collection<int, PosDevice> */
    public function devices(Branch $branch): Collection
    {
        return PosDevice::forCompany($branch->company_id)->where('branch_id', $branch->id)
            ->where('status', PosDeviceStatus::Active->value)->orderBy('name')->get();
    }

    public function device(Branch $branch, ?string $uuid): ?PosDevice
    {
        return $uuid === null || $uuid === '' ? null
            : PosDevice::forCompany($branch->company_id)->where('branch_id', $branch->id)
                ->where('status', PosDeviceStatus::Active->value)->where('uuid', $uuid)->first();
    }

    public function sellingLocation(Branch $branch, ?PosDevice $device): ?StockLocation
    {
        $sellable = StockLocation::forCompany($branch->company_id)->where('branch_id', $branch->id)
            ->where('is_active', true)->where('is_sellable', true);

        if ($device?->stock_location_id !== null && $device !== null) {
            return (clone $sellable)->whereKey($device->stock_location_id)->first();
        }

        return $sellable->orderByRaw('type = ? desc', [LocationType::SalesFloor->value])->orderBy('id')->first();
    }
}
