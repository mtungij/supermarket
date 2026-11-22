<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A company's subscription to a plan. Billing integration is out of scope
 * for Phase 1; status transitions are managed by platform code only, so
 * every attribute is guarded.
 */
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use BelongsToCompany, HasFactory;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'subscription_plan_id' => 'integer',
            'status' => SubscriptionStatus::class,
            'starts_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function isUsable(): bool
    {
        return $this->status->isUsable()
            && $this->starts_at->lte(now())
            && ($this->ends_at === null || $this->ends_at->isFuture());
    }

    public function daysRemaining(): ?int
    {
        return $this->ends_at === null ? null : (int) max(0, ceil(now()->diffInDays($this->ends_at, false)));
    }
}
