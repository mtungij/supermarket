<?php

namespace App\Services\Inventory;

use App\Enums\AdjustmentDirection;
use App\Enums\AdjustmentReason;
use App\Enums\StockAdjustmentStatus;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InvalidDocumentStateException;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Models\StockBatch;
use App\Models\StockLocation;
use App\Models\User;
use App\Services\Catalogue\UnitConversionService;
use App\Services\Support\Authorizer;
use App\Services\Support\DocumentNumberGenerator;
use App\Services\Support\SubscriptionGuard;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Adjustments always need a reason, notes and a responsible user, and are
 * ALWAYS approved by someone holding inventory.adjust.approve before stock
 * changes:  request (pending_approval) -> approve (posted) | reject.
 *
 * Costing: "out" lines leave at the branch weighted-average cost; "in"
 * lines (found goods, positive count corrections) enter at the current
 * average cost, falling back to the product's latest purchase cost.
 */
class StockAdjustmentService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
        private readonly UnitConversionService $conversions,
        private readonly StockLedger $ledger,
        private readonly SubscriptionGuard $subscription,
        private readonly Authorizer $authorizer,
    ) {}

    /**
     * @param  list<array{product_id: int, product_unit_id: int, quantity: string, direction?: ?string, batch_id?: ?int, notes?: ?string}>  $lines
     */
    public function request(StockLocation $location, AdjustmentReason $reason, string $notes, array $lines, User $actor): StockAdjustment
    {
        $this->authorizer->permission($actor, 'inventory.adjust');
        $this->authorizer->branch($actor, $location->branch_id);
        $company = Company::query()->findOrFail($location->company_id);
        $this->subscription->ensureWritable($company, 'notes');

        if (trim($notes) === '') {
            throw BusinessRuleException::make('notes', __('Explain why this adjustment is needed.'));
        }

        if (! $location->is_active) {
            throw BusinessRuleException::make('location_id', __('Select an active location.'));
        }

        return DB::transaction(function () use ($company, $location, $reason, $notes, $lines, $actor) {
            $adjustment = new StockAdjustment;
            $adjustment->forceFill([
                'company_id' => $company->id,
                'branch_id' => $location->branch_id,
                'location_id' => $location->id,
                'reference' => $this->numbers->next($company, DocumentNumberGenerator::ADJUSTMENT),
                'reason' => $reason,
                'status' => StockAdjustmentStatus::PendingApproval,
                'notes' => trim($notes),
                'requested_by' => $actor->id,
                'submitted_at' => now(),
            ])->save();

            if ($lines === []) {
                throw BusinessRuleException::make('lines', __('Add at least one product.'));
            }

            foreach (array_values($lines) as $i => $line) {
                $this->addLine($adjustment, $reason, $line, "lines.{$i}");
            }

            $adjustment->recordEvent('requested', null, StockAdjustmentStatus::PendingApproval->value, $actor, $notes);

            return $adjustment;
        });
    }

    public function approve(StockAdjustment $adjustment, User $approver, ?string $notes = null): StockAdjustment
    {
        $this->authorizer->permission($approver, 'inventory.adjust.approve');

        return DB::transaction(function () use ($adjustment, $approver, $notes) {
            $adjustment = $this->lockPending($adjustment);
            $this->authorizer->branch($approver, $adjustment->branch_id);
            $company = Company::query()->findOrFail($adjustment->company_id);
            $this->subscription->ensureWritable($company, 'status');

            $location = StockLocation::forCompany($company)->where('branch_id', $adjustment->branch_id)->findOrFail($adjustment->location_id);
            $lines = StockAdjustmentLine::forCompany($company)->where('stock_adjustment_id', $adjustment->id)
                ->orderBy('product_id')->orderBy('id')->get();

            foreach ($lines as $line) {
                $product = Product::forCompany($company)->findOrFail($line->product_id);
                $inbound = $line->direction === AdjustmentDirection::In;

                $unitCost = null;
                if ($inbound) {
                    $average = $this->ledger->averageCost($company->id, $location->branch_id, $product->id);
                    $unitCost = Decimal::isPositive($average) ? $average : (string) $product->purchase_cost;
                }

                $movement = $this->ledger->post(new StockPosting(
                    type: $inbound ? StockMovementType::AdjustmentIn : StockMovementType::AdjustmentOut,
                    location: $location,
                    product: $product,
                    quantityBase: (string) $line->quantity_base,
                    source: $line,
                    reference: $adjustment->reference,
                    batch: $line->batch_id ? StockBatch::forCompany($company)->find($line->batch_id) : null,
                    unitCost: $unitCost,
                    productUnit: ProductUnit::forCompany($company)->find($line->product_unit_id),
                    enteredQuantity: (string) $line->quantity,
                    conversionFactor: (string) $line->conversion_factor,
                    user: $approver,
                ));

                $line->forceFill(['unit_cost_base' => $movement->unit_cost])->save();
            }

            $adjustment->forceFill([
                'status' => StockAdjustmentStatus::Posted,
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'posted_at' => now(),
            ])->save();
            $adjustment->recordEvent('approved', 'pending_approval', 'posted', $approver, $notes);

            return $adjustment;
        }, attempts: 3);
    }

    public function reject(StockAdjustment $adjustment, User $approver, string $reason): StockAdjustment
    {
        $this->authorizer->permission($approver, 'inventory.adjust.approve');

        return DB::transaction(function () use ($adjustment, $approver, $reason) {
            $adjustment = $this->lockPending($adjustment);
            $this->authorizer->branch($approver, $adjustment->branch_id);

            $adjustment->forceFill([
                'status' => StockAdjustmentStatus::Rejected, 'rejected_by' => $approver->id,
                'rejected_at' => now(), 'rejection_reason' => $reason,
            ])->save();
            $adjustment->recordEvent('rejected', 'pending_approval', 'rejected', $approver, $reason);

            return $adjustment;
        });
    }

    private function lockPending(StockAdjustment $adjustment): StockAdjustment
    {
        $adjustment = StockAdjustment::forCompany($adjustment->company_id)->lockForUpdate()->findOrFail($adjustment->id);

        if ($adjustment->status !== StockAdjustmentStatus::PendingApproval) {
            throw InvalidDocumentStateException::make('status', __('Adjustment :ref is already :status.', [
                'ref' => $adjustment->reference, 'status' => __($adjustment->status->label()),
            ]));
        }

        return $adjustment;
    }

    private function addLine(StockAdjustment $adjustment, AdjustmentReason $reason, array $line, string $field): void
    {
        $product = Product::forCompany($adjustment->company_id)->find($line['product_id'] ?? 0)
            ?? throw BusinessRuleException::make("{$field}.product_id", __('Select a valid product.'));

        $unit = ProductUnit::forCompany($adjustment->company_id)->where('product_id', $product->id)->where('is_active', true)
            ->find($line['product_unit_id'] ?? 0)
            ?? throw BusinessRuleException::make("{$field}.product_unit_id", __('Select a valid unit for :product.', ['product' => $product->name]));

        $allowed = $reason->allowedDirections();
        $direction = AdjustmentDirection::tryFrom((string) ($line['direction'] ?? '')) ?? (count($allowed) === 1 ? $allowed[0] : null);

        if ($direction === null || ! in_array($direction, $allowed, true)) {
            throw BusinessRuleException::make("{$field}.direction", __(':reason adjustments cannot :direction stock.', [
                'reason' => __($reason->label()), 'direction' => $direction === AdjustmentDirection::In ? __('increase') : __('decrease'),
            ]));
        }

        $batch = null;
        if ($product->requiresBatch()) {
            $batch = StockBatch::forCompany($adjustment->company_id)->where('product_id', $product->id)->find($line['batch_id'] ?? 0)
                ?? throw BusinessRuleException::make("{$field}.batch_id", __(':product is batch/expiry tracked: select a batch.', ['product' => $product->name]));
        }

        $quantityBase = $this->conversions->toBase($product, $unit, (string) $line['quantity'], "{$field}.quantity");

        $record = new StockAdjustmentLine;
        $record->forceFill([
            'company_id' => $adjustment->company_id,
            'stock_adjustment_id' => $adjustment->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'batch_id' => $batch?->id,
            'direction' => $direction,
            'conversion_factor' => $unit->factor,
            'quantity' => Decimal::normalize((string) $line['quantity'], Decimal::QTY),
            'quantity_base' => $quantityBase,
            'notes' => $line['notes'] ?? null,
        ])->save();
    }
}
