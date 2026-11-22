<?php

namespace App\Services\Inventory;

use App\Exceptions\BusinessRuleException;
use App\Models\Product;
use App\Models\StockBatch;
use App\Support\LockedRow;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Finds or creates the lot for a receipt line.
 * - batch-tracked products need a batch number
 * - expiry-tracked products need an expiry date; if they are not also
 *   batch-tracked, an automatic lot per expiry date is used (EXP-YYYYMMDD)
 * - an existing batch number must keep its original expiry date
 */
class BatchResolver
{
    public function resolve(Product $product, ?string $batchNumber, ?string $expiresOn, ?string $manufacturedOn, string $field = 'batch_number'): ?StockBatch
    {
        if (! $product->requiresBatch()) {
            return null;
        }

        $batchNumber = strtoupper(trim((string) $batchNumber));
        $expiry = $this->date($expiresOn, 'expires_on');
        $manufactured = $this->date($manufacturedOn, 'manufactured_on');

        if ($product->track_batches && $batchNumber === '') {
            throw BusinessRuleException::make($field, __(':product requires a batch number.', ['product' => $product->name]));
        }

        if ($product->track_expiry && $expiry === null) {
            throw BusinessRuleException::make('expires_on', __(':product requires an expiry date.', ['product' => $product->name]));
        }

        if ($expiry !== null && $manufactured !== null && $manufactured->gt($expiry)) {
            throw BusinessRuleException::make('manufactured_on', __('The manufacturing date must be before the expiry date.'));
        }

        if ($manufactured !== null && $manufactured->isFuture()) {
            throw BusinessRuleException::make('manufactured_on', __('The manufacturing date cannot be in the future.'));
        }

        if ($batchNumber === '') {
            $batchNumber = 'EXP-'.$expiry->format('Ymd');
        }

        if (! preg_match('/^[A-Z0-9\-_.\/]{1,64}$/', $batchNumber)) {
            throw BusinessRuleException::make($field, __('Batch numbers may contain letters, digits and - _ . / (max 64).'));
        }

        $batch = LockedRow::lockOrCreate(
            fn () => StockBatch::forCompany($product->company_id)->where('product_id', $product->id)->where('batch_number', $batchNumber),
            fn () => DB::table('stock_batches')->insertOrIgnore([
                'company_id' => $product->company_id, 'product_id' => $product->id, 'batch_number' => $batchNumber,
                'manufactured_on' => $manufactured?->toDateString(), 'expires_on' => $expiry?->toDateString(),
                'created_at' => now(), 'updated_at' => now(),
            ]),
        );

        if ($expiry !== null && $batch->expires_on !== null && ! $batch->expires_on->isSameDay($expiry)) {
            throw BusinessRuleException::make('expires_on', __('Batch :batch is already recorded with expiry :date.', [
                'batch' => $batchNumber, 'date' => $batch->expires_on->format('d/m/Y'),
            ]));
        }

        return $batch;
    }

    private function date(?string $value, string $field): ?CarbonInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            throw BusinessRuleException::make($field, __('Enter a valid date.'));
        }
    }
}
