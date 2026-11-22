<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

/**
 * Seeds ONLY the onboarding trial plan required for company registration.
 * Commercial plans (names, prices, limits) are business decisions and are
 * created by the platform operator, not seeded with invented values.
 */
class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        SubscriptionPlan::query()->firstOrCreate(
            ['code' => config('saas.onboarding_plan')],
            [
                'name' => 'Free Trial',
                'description' => 'Trial plan assigned to newly registered companies.',
                'price' => 0,
                'currency' => config('saas.default_currency'),
                'duration_days' => 30,
                'trial_days' => 14,
                'max_branches' => 1,
                'max_staff' => 5,
                'max_pos_devices' => 2,
                'is_active' => true,
                'is_public' => false,
                'sort_order' => 0,
            ],
        );
    }
}
