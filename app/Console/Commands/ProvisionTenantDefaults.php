<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Catalogue\CompanyDefaultsProvisioner;
use Illuminate\Console\Command;

/**
 * Backfills default units, categories and stock locations for companies
 * created before Phase 2 (or after configuration changes). Idempotent:
 * only missing defaults are inserted; existing records are never changed.
 */
class ProvisionTenantDefaults extends Command
{
    protected $signature = 'tenants:provision-defaults {--company= : Company UUID (default: all companies)}';

    protected $description = 'Create missing default units, categories, stock locations and payment methods for companies';

    public function handle(CompanyDefaultsProvisioner $provisioner): int
    {
        $query = Company::query()->orderBy('id');

        if ($uuid = $this->option('company')) {
            $query->where('uuid', $uuid);
        }

        $query->each(function (Company $company) use ($provisioner) {
            $created = $provisioner->provision($company);
            $this->line(sprintf('%s: +%d units, +%d categories, +%d locations, +%d payment methods', $company->name, $created['units'], $created['categories'], $created['locations'], $created['payment_methods']));
        });

        return self::SUCCESS;
    }
}
