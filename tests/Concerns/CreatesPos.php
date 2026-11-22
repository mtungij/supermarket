<?php

namespace Tests\Concerns;

use App\Enums\LocationType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\PosDevice;
use App\Models\Product;
use App\Models\User;
use App\Services\Pos\CheckoutService;
use Illuminate\Support\Str;

/** POS fixtures built through the real services. Requires CreatesTenants + CreatesInventory. */
trait CreatesPos
{
    protected function device(Branch $branch, string $name = 'Till 1', int $width = 80): PosDevice
    {
        $device = new PosDevice(['name' => $name, 'receipt_paper_width' => $width]);
        $device->forceFill(['company_id' => $branch->company_id, 'branch_id' => $branch->id, 'status' => 'active'])->save();

        return $device;
    }

    protected function method(Company $company, string $code = 'cash'): PaymentMethod
    {
        return PaymentMethod::forCompany($company)->where('code', $code)->firstOrFail();
    }

    /** Receive $qty base units of $product directly into a location of the head office. */
    protected function stock(Company $company, User $owner, Product $product, string $qty, string $cost = '1000', LocationType $type = LocationType::SalesFloor, array $line = []): void
    {
        $order = $this->approvedOrder($company, $this->headOffice($company), $this->makeSupplier($company, 'Supplier '.Str::random(5)), [[$product, $this->baseUnitOf($product), $qty, $cost]], $owner);
        $this->receiveGoods($order, [array_merge(['purchase_order_line_id' => $order->lines()->sole()->id, 'product_unit_id' => $this->baseUnitOf($product)->id, 'quantity' => $qty], $line)], $owner, $this->location($this->headOffice($company), $type));
    }

    protected function sell(Company $company, User $cashier, ?PosDevice $device, array $lines, array $payments, ?string $key = null, ?int $customerId = null)
    {
        return app(CheckoutService::class)->checkout($company, $this->headOffice($company), $device, $cashier, $key ?? (string) Str::uuid(), $lines, $payments, $customerId);
    }
}
