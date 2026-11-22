<?php

namespace App\Models\Concerns;

use LogicException;

/**
 * Ledger-style records: insert only. Updates and deletes are refused at
 * the model layer (and nothing in the application issues raw UPDATE or
 * DELETE statements against these tables).
 */
trait IsImmutable
{
    public static function bootIsImmutable(): void
    {
        static::updating(function ($model): void {
            throw new LogicException($model::class.' records are immutable.');
        });

        static::deleting(function ($model): void {
            throw new LogicException($model::class.' records are immutable.');
        });
    }
}
