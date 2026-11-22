<?php

namespace Database\Factories;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SubscriptionPlan> */
class SubscriptionPlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'plan-'.fake()->unique()->lexify('??????'),
            'name' => 'Test Plan',
            'price' => 0,
            'currency' => 'TZS',
            'duration_days' => 30,
            'trial_days' => 0,
            'max_branches' => 3,
            'max_staff' => 10,
            'max_pos_devices' => 3,
            'is_active' => true,
            'is_public' => true,
            'sort_order' => 0,
        ];
    }

    public function unlimited(): static
    {
        return $this->state(['max_branches' => null, 'max_staff' => null, 'max_pos_devices' => null]);
    }
}
