<?php

namespace Database\Factories;

use App\Enums\SystemRole;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CompanyUser> */
class CompanyUserFactory extends Factory
{
    protected $model = CompanyUser::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'user_id' => User::factory(),
            'role_id' => fn () => Role::system(SystemRole::Cashier->value)->id,
            'is_active' => true,
            'all_branches' => false,
            'joined_at' => now(),
        ];
    }

    public function role(SystemRole $role): static
    {
        return $this->state(fn () => [
            'role_id' => Role::system($role->value)->id,
            'all_branches' => $role->allBranches(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false, 'deactivated_at' => now()]);
    }
}
