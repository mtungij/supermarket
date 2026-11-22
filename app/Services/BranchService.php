<?php

namespace App\Services;

use App\Enums\PlanLimit;
use App\Models\Branch;
use App\Models\Company;
use App\Services\Catalogue\CompanyDefaultsProvisioner;
use Illuminate\Support\Facades\DB;

class BranchService
{
    public function __construct(private readonly SubscriptionLimiter $limiter) {}

    /**
     * @param  array{name: string, code?: string|null, phone?: string|null, email?: string|null, address?: string|null}  $data
     */
    public function create(Company $company, array $data): Branch
    {
        return DB::transaction(function () use ($company, $data) {
            $company = $this->limiter->lockCompany($company);
            $this->limiter->ensureCanAdd($company, PlanLimit::Branches, 'name');

            $branch = $company->branches()->create([
                'name' => $data['name'],
                'code' => filled($data['code'] ?? null) ? strtoupper($data['code']) : $this->nextCode($company),
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'address' => $data['address'] ?? null,
                'is_head_office' => false,
                'is_active' => true,
            ]);

            // Main Store, Sales Floor and Receiving Area for the new branch.
            app(CompanyDefaultsProvisioner::class)->provisionBranch($branch);

            return $branch;
        });
    }

    private function nextCode(Company $company): string
    {
        $n = $company->branches()->withTrashed()->count() + 1;

        do {
            $code = sprintf('BR%03d', $n++);
        } while ($company->branches()->withTrashed()->where('code', $code)->exists());

        return $code;
    }
}
