<?php

namespace App\Services;

use App\Enums\CompanyStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\SystemRole;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Catalogue\CompanyDefaultsProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Registers a new company (tenant) with its first branch, owner,
 * membership and subscription in a single database transaction.
 *
 * This service can never grant platform roles: it only ever assigns the
 * tenant "Company Owner" role.
 */
class CompanyOnboardingService
{
    /**
     * @param  array{
     *     company_name: string, company_email: string, company_phone: string,
     *     business_address: string, branch_name: string,
     *     owner_name: string, owner_email: string, password: string
     * }  $data  Validated input.
     * @return array{company: Company, owner: User}
     */
    public function register(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $plan = SubscriptionPlan::query()
                ->where('code', config('saas.onboarding_plan'))
                ->where('is_active', true)
                ->first();

            if ($plan === null) {
                throw new RuntimeException('Onboarding subscription plan is not configured. Run the database seeders.');
            }

            $owner = $this->resolveOwner($data);

            $company = new Company([
                'name' => $data['company_name'],
                'email' => Str::lower($data['company_email']),
                'phone' => $data['company_phone'],
                'address' => $data['business_address'],
                'country_code' => config('saas.default_country'),
                'currency' => config('saas.default_currency'),
                'timezone' => config('saas.default_timezone'),
                'locale' => app()->getLocale(),
                'settings' => config('saas.default_company_settings'),
            ]);
            $company->slug = $this->uniqueSlug($data['company_name']);
            $company->status = CompanyStatus::Active;
            $company->owner_user_id = $owner->id;
            $company->save();

            $company->branches()->create([
                'name' => $data['branch_name'],
                'code' => 'BR001',
                'is_head_office' => true,
                'is_active' => true,
            ]);

            $ownerRole = Role::system(SystemRole::CompanyOwner->value);

            CompanyUser::query()->forceCreate([
                'company_id' => $company->id,
                'user_id' => $owner->id,
                'role_id' => $ownerRole->id,
                'is_active' => true,
                'all_branches' => true,
                'joined_at' => now(),
            ]);

            $this->startSubscription($company, $plan);

            // Default units, categories and per-branch stock locations (idempotent).
            app(CompanyDefaultsProvisioner::class)->provision($company);

            return ['company' => $company, 'owner' => $owner];
        });
    }

    public function startSubscription(Company $company, SubscriptionPlan $plan): Subscription
    {
        $now = now();
        $trial = $plan->trial_days > 0;
        $endsAt = $now->copy()->addDays($trial ? $plan->trial_days : $plan->duration_days);

        $subscription = new Subscription;
        $subscription->forceFill([
            'company_id' => $company->id,
            'subscription_plan_id' => $plan->id,
            'status' => $trial ? SubscriptionStatus::Trialing : SubscriptionStatus::Active,
            'starts_at' => $now,
            'trial_ends_at' => $trial ? $endsAt : null,
            'ends_at' => $endsAt,
        ])->save();

        return $subscription;
    }

    /**
     * Create the owner account, or associate an EXISTING account only when
     * the supplied password proves ownership of it.
     */
    private function resolveOwner(array $data): User
    {
        $email = Str::lower($data['owner_email']);
        $existing = User::query()->where('email', $email)->lockForUpdate()->first();

        if ($existing === null) {
            $user = new User([
                'name' => $data['owner_name'],
                'email' => $email,
                'password' => $data['password'],
                'locale' => app()->getLocale(),
            ]);
            $user->is_active = true;
            $user->save();

            return $user;
        }

        if (! Hash::check($data['password'], $existing->password)
            || ! $existing->is_active
            || $existing->isPlatformAdmin()) {
            throw ValidationException::withMessages([
                'owner_email' => __('This email is already registered. Enter that account\'s current password to add a new company to it.'),
            ]);
        }

        return $existing;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'company';
        $slug = $base;

        while (Company::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(5));
        }

        return $slug;
    }
}
