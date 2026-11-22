<?php

namespace App\Services;

use App\Enums\PlanLimit;
use App\Enums\PosDeviceStatus;
use App\Exceptions\PlanLimitExceededException;
use App\Models\Company;
use App\Models\CompanyUser;

/**
 * Evaluates subscription plan limits for a company.
 *
 * A company without a usable (trialing/active, unexpired) subscription
 * has a limit of 0 for every resource: existing data stays accessible,
 * but nothing new can be added until the subscription is renewed.
 */
class SubscriptionLimiter
{
    /** NULL = unlimited. */
    public function limit(Company $company, PlanLimit $limit): ?int
    {
        $subscription = $company->currentSubscription();

        if ($subscription === null) {
            return 0;
        }

        return $subscription->plan->limit($limit);
    }

    public function usage(Company $company, PlanLimit $limit): int
    {
        return match ($limit) {
            PlanLimit::Branches => $company->branches()->count(),
            PlanLimit::Staff => CompanyUser::query()
                ->where('company_id', $company->id)
                ->where('is_active', true)
                ->count(),
            PlanLimit::PosDevices => $company->posDevices()
                ->where('status', '!=', PosDeviceStatus::Revoked->value)
                ->count(),
        };
    }

    public function canAdd(Company $company, PlanLimit $limit, int $quantity = 1): bool
    {
        $max = $this->limit($company, $limit);

        return $max === null || $this->usage($company, $limit) + $quantity <= $max;
    }

    /**
     * Must be called inside a transaction after locking the company row
     * (see lockCompany) so concurrent requests cannot both pass the check.
     *
     * @throws PlanLimitExceededException
     */
    public function ensureCanAdd(Company $company, PlanLimit $limit, string $field = 'plan'): void
    {
        if (! $this->canAdd($company, $limit)) {
            throw PlanLimitExceededException::for($limit, $this->limit($company, $limit), $field);
        }
    }

    /** Serialises limit checks for one company. Call within DB::transaction. */
    public function lockCompany(Company $company): Company
    {
        return Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @return array<string, array{limit: int|null, usage: int}>
     */
    public function summary(Company $company): array
    {
        $summary = [];

        foreach (PlanLimit::cases() as $limit) {
            $summary[$limit->value] = [
                'limit' => $this->limit($company, $limit),
                'usage' => $this->usage($company, $limit),
            ];
        }

        return $summary;
    }
}
