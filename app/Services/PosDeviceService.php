<?php

namespace App\Services;

use App\Enums\PlanLimit;
use App\Enums\PosDeviceStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\PosDevice;
use App\Models\StockLocation;
use App\Models\User;
use App\Support\Tenancy\TenantViolationException;
use Illuminate\Support\Facades\DB;

class PosDeviceService
{
    public function __construct(private readonly SubscriptionLimiter $limiter) {}

    public function register(Company $company, Branch $branch, string $name, int $receiptWidth, ?User $registeredBy = null): PosDevice
    {
        if ($branch->company_id !== $company->id) {
            throw new TenantViolationException('Branch does not belong to this company.');
        }

        return DB::transaction(function () use ($company, $branch, $name, $receiptWidth, $registeredBy) {
            $company = $this->limiter->lockCompany($company);
            $this->limiter->ensureCanAdd($company, PlanLimit::PosDevices, 'name');

            $device = new PosDevice([
                'name' => $name,
                'receipt_paper_width' => $receiptWidth,
            ]);
            $device->company_id = $company->id;
            $device->branch_id = $branch->id;
            $device->status = PosDeviceStatus::Active;
            $device->registered_by = $registeredBy?->id;
            $device->save();

            return $device;
        });
    }

    public function revoke(PosDevice $device): void
    {
        $device->status = PosDeviceStatus::Revoked;
        $device->save();
    }

    /**
     * Register settings: receipt paper width and an optional selling location
     * (must be an active, sellable location of the device's branch).
     */
    public function configure(PosDevice $device, int $receiptWidth, ?StockLocation $location): void
    {
        if (! in_array($receiptWidth, config('pos.receipt_paper_widths'), true)) {
            throw BusinessRuleException::make('receiptPaperWidth', __('Choose 58 or 80 mm paper.'));
        }

        if ($location !== null && ($location->branch_id !== $device->branch_id || ! $location->is_active || ! $location->is_sellable)) {
            throw BusinessRuleException::make('location', __('Choose an active sellable location of this branch.'));
        }

        $device->forceFill(['receipt_paper_width' => $receiptWidth, 'stock_location_id' => $location?->id])->save();
    }
}
