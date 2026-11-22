<?php

namespace App\Services\Catalogue;

/**
 * Immutable result of a price lookup. The future POS stores these values
 * on the sale line as its price snapshot; historical sales are never
 * re-priced from the current price list.
 */
final readonly class PriceQuote
{
    public function __construct(
        public string $price,              // per selected unit, 2 dp
        public int $productUnitId,
        public string $conversionFactor,
        public ?int $branchId,             // branch-specific record used, if any
        public ?int $productPriceId,       // NULL when derived
        public string $source,             // branch_unit|company_unit|default_base|derived_from_base
    ) {}
}
