<?php

namespace Database\Factories;

use App\Enums\CompanyStatus;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Company> */
class CompanyFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'email' => fake()->unique()->companyEmail(),
            'phone' => '+255 7'.fake()->numerify('## ### ###'),
            'address' => fake()->address(),
            'country_code' => 'TZ',
            'currency' => 'TZS',
            'timezone' => 'Africa/Dar_es_Salaam',
            'locale' => 'en',
            'status' => CompanyStatus::Active,
            'settings' => config('saas.default_company_settings'),
        ];
    }

    public function suspended(): static
    {
        return $this->state(['status' => CompanyStatus::Suspended]);
    }
}
