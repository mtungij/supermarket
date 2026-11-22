<?php

namespace App\Services\Inventory;

use App\Enums\StockMovementType;
use App\Enums\StockTransferStatus;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InvalidDocumentStateException;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockBatch;
use App\Models\StockLocation;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Models\User;
use App\Services\Catalogue\UnitConversionService;
use App\Services\Support\Authorizer;
use App\Services\Support\DocumentNumberGenerator;
use App\Services\Support\SubscriptionGuard;
use App\Support\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Stock transfers.
 *
 * Between branches:  draft -> dispatch (transfer_out at source; goods are
 * IN TRANSIT and belong to neither branch's on-hand stock) -> receive
 * (transfer_in at destination at the dispatch cost snapshot).
 *
 * Within one branch (location to location): dispatch posts transfer_out
 * and transfer_in together and the transfer is immediately received.
 *
 * Dispatch and receipt each lock the transfer row and check its status,
 * and stock movements are unique per (line, type): goods can be neither
 * dispatched nor received twice.
 */
class StockTransferService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
        private readonly UnitConversionService $conversions,
        private readonly StockLedger $ledger,
        private readonly SubscriptionGuard $subscription,
        private readonly Authorizer $authorizer,
    ) {}

    /** @param list<array{product_id: int, product_unit_id: int, quantity: string, batch_id?: ?int}> $lines */
    public function create(StockLocation $source, StockLocation $destination, array $lines, User $actor, ?string $notes = null, ?string $submissionKey = null): StockTransfer
    {
        // Idempotent per submission key (double-clicked "Save").
        if ($submissionKey !== null && ($existing = StockTransfer::forCompany($source->company_id)->where('submission_key', $submissionKey)->first()) !== null) {
            return $existing;
        }

        $this->authorizer->permission($actor, 'inventory.transfer');
        $this->authorizer->branch($actor, $source->branch_id);
        $company = Company::query()->findOrFail($source->company_id);
        $this->subscription->ensureWritable($company, 'lines');

        if ($destination->company_id !== $source->company_id) {
            throw BusinessRuleException::make('destination_location_id', __('Select a valid destination.'));
        }

        if ($destination->id === $source->id) {
            throw BusinessRuleException::make('destination_location_id', __('The destination must differ from the source.'));
        }

        if (! $source->is_active || ! $destination->is_active) {
            throw BusinessRuleException::make('destination_location_id', __('Both locations must be active.'));
        }

        return DB::transaction(function () use ($company, $source, $destination, $lines, $actor, $notes, $submissionKey) {
            $transfer = new StockTransfer;
            $transfer->forceFill([
                'company_id' => $company->id,
                'reference' => $this->numbers->next($company, DocumentNumberGenerator::TRANSFER),
                'submission_key' => $submissionKey,
                'source_branch_id' => $source->branch_id,
                'source_location_id' => $source->id,
                'destination_branch_id' => $destination->branch_id,
                'destination_location_id' => $destination->id,
                'status' => StockTransferStatus::Draft,
                'notes' => $notes,
                'created_by' => $actor->id,
            ])->save();

            if ($lines === []) {
                throw BusinessRuleException::make('lines', __('Add at least one product.'));
            }

            foreach (array_values($lines) as $i => $line) {
                $this->addLine($transfer, $line, "lines.{$i}");
            }

            $transfer->recordEvent('created', null, 'draft', $actor);

            return $transfer;
        });
    }

    public function dispatch(StockTransfer $transfer, User $actor): StockTransfer
    {
        $this->authorizer->permission($actor, 'inventory.transfer');

        return DB::transaction(function () use ($transfer, $actor) {
            $transfer = $this->lockInStatus($transfer, StockTransferStatus::Draft, __('dispatched'));
            $this->authorizer->branch($actor, $transfer->source_branch_id);
            $company = Company::query()->findOrFail($transfer->company_id);
            $this->subscription->ensureWritable($company, 'status');

            $source = StockLocation::forCompany($company)->findOrFail($transfer->source_location_id);

            foreach ($this->lines($transfer) as $line) {
                $movement = $this->ledger->post($this->posting(StockMovementType::TransferOut, $source, $line, $transfer, $actor));
                $line->forceFill(['unit_cost_base' => $movement->unit_cost])->save();
            }

            $transfer->forceFill([
                'status' => StockTransferStatus::InTransit, 'dispatched_by' => $actor->id, 'dispatched_at' => now(),
            ])->save();
            $transfer->recordEvent('dispatched', 'draft', 'in_transit', $actor);

            // Same branch: the goods arrive immediately.
            if (! $transfer->isInterBranch()) {
                return $this->receiveLocked($transfer, $company, $actor);
            }

            return $transfer;
        }, attempts: 3);
    }

    public function receive(StockTransfer $transfer, User $actor): StockTransfer
    {
        $this->authorizer->permission($actor, 'inventory.transfer.receive');

        return DB::transaction(function () use ($transfer, $actor) {
            $transfer = $this->lockInStatus($transfer, StockTransferStatus::InTransit, __('received'));
            $this->authorizer->branch($actor, $transfer->destination_branch_id);
            $company = Company::query()->findOrFail($transfer->company_id);

            return $this->receiveLocked($transfer, $company, $actor);
        }, attempts: 3);
    }

    public function cancel(StockTransfer $transfer, User $actor): StockTransfer
    {
        $this->authorizer->permission($actor, 'inventory.transfer');

        return DB::transaction(function () use ($transfer, $actor) {
            $transfer = $this->lockInStatus($transfer, StockTransferStatus::Draft, __('cancelled'));
            $this->authorizer->branch($actor, $transfer->source_branch_id);
            $transfer->forceFill(['status' => StockTransferStatus::Cancelled, 'cancelled_by' => $actor->id, 'cancelled_at' => now()])->save();
            $transfer->recordEvent('cancelled', 'draft', 'cancelled', $actor);

            return $transfer;
        });
    }

    private function receiveLocked(StockTransfer $transfer, Company $company, User $actor): StockTransfer
    {
        $destination = StockLocation::forCompany($company)->findOrFail($transfer->destination_location_id);

        foreach ($this->lines($transfer) as $line) {
            $this->ledger->post($this->posting(StockMovementType::TransferIn, $destination, $line, $transfer, $actor, (string) $line->unit_cost_base));
        }

        $transfer->forceFill(['status' => StockTransferStatus::Received, 'received_by' => $actor->id, 'received_at' => now()])->save();
        $transfer->recordEvent('received', 'in_transit', 'received', $actor);

        return $transfer;
    }

    private function posting(StockMovementType $type, StockLocation $location, StockTransferLine $line, StockTransfer $transfer, User $actor, ?string $unitCost = null): StockPosting
    {
        return new StockPosting(
            type: $type,
            location: $location,
            product: Product::forCompany($transfer->company_id)->findOrFail($line->product_id),
            quantityBase: (string) $line->quantity_base,
            source: $line,
            reference: $transfer->reference,
            batch: $line->batch_id ? StockBatch::forCompany($transfer->company_id)->find($line->batch_id) : null,
            unitCost: $unitCost,
            productUnit: ProductUnit::forCompany($transfer->company_id)->find($line->product_unit_id),
            enteredQuantity: (string) $line->quantity,
            conversionFactor: (string) $line->conversion_factor,
            user: $actor,
        );
    }

    /** @return Collection<int, StockTransferLine> */
    private function lines(StockTransfer $transfer)
    {
        return StockTransferLine::forCompany($transfer->company_id)->where('stock_transfer_id', $transfer->id)
            ->orderBy('product_id')->orderBy('id')->get();
    }

    private function lockInStatus(StockTransfer $transfer, StockTransferStatus $expected, string $action): StockTransfer
    {
        $transfer = StockTransfer::forCompany($transfer->company_id)->lockForUpdate()->findOrFail($transfer->id);

        if ($transfer->status !== $expected) {
            throw InvalidDocumentStateException::make('status', __('Transfer :ref is :status and cannot be :action.', [
                'ref' => $transfer->reference, 'status' => __($transfer->status->label()), 'action' => $action,
            ]));
        }

        return $transfer;
    }

    private function addLine(StockTransfer $transfer, array $line, string $field): void
    {
        $product = Product::forCompany($transfer->company_id)->find($line['product_id'] ?? 0)
            ?? throw BusinessRuleException::make("{$field}.product_id", __('Select a valid product.'));

        $unit = ProductUnit::forCompany($transfer->company_id)->where('product_id', $product->id)->where('is_active', true)
            ->find($line['product_unit_id'] ?? 0)
            ?? throw BusinessRuleException::make("{$field}.product_unit_id", __('Select a valid unit for :product.', ['product' => $product->name]));

        $batch = null;
        if ($product->requiresBatch()) {
            $batch = StockBatch::forCompany($transfer->company_id)->where('product_id', $product->id)->find($line['batch_id'] ?? 0)
                ?? throw BusinessRuleException::make("{$field}.batch_id", __(':product is batch/expiry tracked: select a batch.', ['product' => $product->name]));
        }

        $quantityBase = $this->conversions->toBase($product, $unit, (string) $line['quantity'], "{$field}.quantity");

        $record = new StockTransferLine;
        $record->forceFill([
            'company_id' => $transfer->company_id,
            'stock_transfer_id' => $transfer->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'batch_id' => $batch?->id,
            'conversion_factor' => $unit->factor,
            'quantity' => Decimal::normalize((string) $line['quantity'], Decimal::QTY),
            'quantity_base' => $quantityBase,
        ])->save();
    }
}
