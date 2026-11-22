<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Lock-or-create for "one row per key" tables (stock balances, costs,
 * batches, sequences).
 *
 * Do NOT use "INSERT IGNORE then SELECT ... FOR UPDATE" on rows that
 * usually exist: on a duplicate key InnoDB takes a SHARED lock, and two
 * transactions holding shared locks deadlock when both upgrade to
 * exclusive. Instead lock the existing row first and only insert when it
 * is missing. (A rare race between two first-time inserts can still
 * deadlock; callers run inside DB::transaction(..., attempts: 3), which
 * retries deadlocked transactions.)
 */
final class LockedRow
{
    /**
     * @template T of Model
     *
     * @param  callable(): Builder<T>  $query
     * @param  callable(): void  $insert
     * @return T
     */
    public static function lockOrCreate(callable $query, callable $insert): Model
    {
        $row = $query()->lockForUpdate()->first();

        if ($row !== null) {
            return $row;
        }

        $insert();

        return $query()->lockForUpdate()->firstOrFail();
    }
}
