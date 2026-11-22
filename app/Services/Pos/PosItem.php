<?php

namespace App\Services\Pos;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Services\Catalogue\PriceQuote;

/** A resolved, sellable product+unit with its current price (stable lookup contract for online and future offline POS). */
final readonly class PosItem
{
    public function __construct(
        public Product $product,
        public ProductUnit $unit,
        public PriceQuote $price,
        public ?string $barcode,
    ) {}
}
