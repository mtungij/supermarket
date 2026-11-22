<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeds reference data only (roles, permissions, onboarding plan).
 * No demo companies or fabricated business data are created.
 * Create the platform administrator with: php artisan platform:create-admin
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            SubscriptionPlanSeeder::class,
        ]);
    }
}
