<?php

namespace App\Services\Inventory;

use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockBatch;
use App\Models\StockLocation;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * One requested stock change, expressed in BASE units.
 *
 * $unitCost is required for inbound postings that bring their own cost
 * (goods receipts, transfer-in). It is ignored for outbound postings,
 * which always leave at the branch's current weighted-average cost.
 */
final readonly class StockPosting
{
    public function __construct(
        public StockMovementType $type,
        public StockLocation $location,
        public Product $product,
        public string $quantityBase,        // positive
        public Model $source,               // the document LINE that causes the movement
        public ?string $reference = null,   // document reference snapshot
        public ?StockBatch $batch = null,
        public ?string $unitCost = null,    // per base unit
        public ?ProductUnit $productUnit = null,
        public ?string $enteredQuantity = null,
        public ?string $conversionFactor = null,
        public ?User $user = null,
        public ?CarbonInterface $occurredAt = null,
    ) {}
}
