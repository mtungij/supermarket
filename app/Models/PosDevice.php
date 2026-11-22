<?php

namespace App\Models;

use App\Enums\PosDeviceStatus;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\PosDeviceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A registered POS terminal / register. branch_id is not fillable; it is
 * set by App\Services\PosDeviceService after verifying the branch belongs
 * to the device's company.
 */
#[Fillable(['name', 'receipt_paper_width'])]
class PosDevice extends Model
{
    /** @use HasFactory<PosDeviceFactory> */
    use BelongsToCompany, HasFactory, HasUuids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'branch_id' => 'integer',
            'stock_location_id' => 'integer',
            'status' => PosDeviceStatus::class,
            'receipt_paper_width' => 'integer',
            'last_seen_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Selling location override (NULL = the branch's default selling location). */
    public function stockLocation(): BelongsTo
    {
        return $this->ownedBelongsTo(StockLocation::class, 'stock_location_id');
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }
}
