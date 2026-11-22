<?php

namespace App\Models;

use App\Enums\PlanLimit;
use Database\Factories\SubscriptionPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Platform-level plan definition. Not tenant-owned.
 */
#[Fillable([
    'code', 'name', 'description', 'price', 'currency', 'duration_days', 'trial_days',
    'max_branches', 'max_staff', 'max_pos_devices', 'is_active', 'is_public', 'sort_order',
])]
class SubscriptionPlan extends Model
{
    /** @use HasFactory<SubscriptionPlanFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'duration_days' => 'integer',
            'trial_days' => 'integer',
            'max_branches' => 'integer',
            'max_staff' => 'integer',
            'max_pos_devices' => 'integer',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class)->withoutGlobalScopes();
    }

    /** NULL means unlimited. */
    public function limit(PlanLimit $limit): ?int
    {
        return $this->getAttribute($limit->planColumn());
    }
}
