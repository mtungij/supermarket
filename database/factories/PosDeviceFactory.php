<?php

namespace Database\Factories;

use App\Enums\PosDeviceStatus;
use App\Models\Branch;
use App\Models\PosDevice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PosDevice> */
class PosDeviceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'company_id' => fn (array $attributes) => Branch::withoutTenancy()->find($attributes['branch_id'])->company_id,
            'name' => 'Register '.fake()->unique()->numberBetween(1, 999),
            'status' => PosDeviceStatus::Active,
            'receipt_paper_width' => 80,
        ];
    }
}
