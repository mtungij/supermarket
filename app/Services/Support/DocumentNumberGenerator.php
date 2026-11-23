<?php

namespace App\Services\Support;

use App\Models\Company;
use App\Models\DocumentSequence;
use App\Support\LockedRow;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Gap-tolerant, per-company sequential numbers (PO-000001, GRN-000001...).
 * Must run inside the transaction that creates the document so the row
 * lock serialises concurrent creators and a rollback releases the number.
 */
class DocumentNumberGenerator
{
    public const PURCHASE_ORDER = 'purchase_order';

    public const GOODS_RECEIPT = 'goods_receipt';

    public const ADJUSTMENT = 'stock_adjustment';

    public const TRANSFER = 'stock_transfer';

    public const INTERNAL_BARCODE = 'internal_barcode';

    public const SALE = 'sale';

    public const SALE_RETURN = 'sale_return';

    public const OPENING_STOCK = 'opening_stock';

    private const PREFIXES = [
        self::PURCHASE_ORDER => 'PO',
        self::GOODS_RECEIPT => 'GRN',
        self::ADJUSTMENT => 'ADJ',
        self::TRANSFER => 'TRF',
        self::SALE => 'S',
        self::SALE_RETURN => 'RET',
        self::OPENING_STOCK => 'OPS',
    ];

    public function nextNumber(Company $company, string $key): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Document numbers must be generated inside a transaction.');
        }

        $sequence = LockedRow::lockOrCreate(
            fn () => DocumentSequence::forCompany($company)->where('key', $key),
            fn () => DB::table('document_sequences')->insertOrIgnore([
                'company_id' => $company->id, 'key' => $key, 'next_number' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]),
        );
        $number = (int) $sequence->next_number;

        DocumentSequence::forCompany($company)->whereKey($sequence->id)->update([
            'next_number' => $number + 1, 'updated_at' => now(),
        ]);

        return $number;
    }

    public function next(Company $company, string $key): string
    {
        return sprintf('%s-%06d', self::PREFIXES[$key] ?? strtoupper($key), $this->nextNumber($company, $key));
    }
}
