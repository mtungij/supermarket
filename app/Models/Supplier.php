<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'phone', 'email', 'address', 'tax_id', 'contact_person', 'notes', 'is_active'])]
class Supplier extends Model
{
    use BelongsToCompany, HasFactory, HasUuids;

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'is_active' => 'boolean'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function purchaseOrders(): HasMany
    {
        return $this->ownedHasMany(PurchaseOrder::class);
    }

    public function transactions(): HasMany
    {
        return $this->ownedHasMany(SupplierTransaction::class);
    }

    /** Amount owed to the supplier (posted receipts minus payments). */
    public function outstandingBalance(): string
    {
        return Decimal::round((string) ($this->transactions()->sum('amount') ?: '0'), Decimal::MONEY);
    }
}
