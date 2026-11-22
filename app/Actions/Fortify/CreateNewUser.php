<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\CompanyOnboardingService;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Public registration = company onboarding.
 *
 * Only the whitelisted fields below are read from input; anything else
 * (e.g. is_platform_admin, platform_role_id, company_id, role) is ignored.
 */
class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    public function __construct(private readonly CompanyOnboardingService $onboarding) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        $this->throttle();

        $data = Validator::make($input, [
            'company_name' => ['required', 'string', 'max:150'],
            'company_email' => ['required', 'string', 'email', 'max:255'],
            'company_phone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9][0-9\s\-]{6,28}$/'],
            'business_address' => ['required', 'string', 'max:500'],
            'branch_name' => ['required', 'string', 'max:100'],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'string', 'email', 'max:255'],
            'password' => $this->passwordRules(),
        ], [], [
            'company_phone' => __('company phone'),
            'owner_email' => __('owner email'),
        ])->validate();

        ['company' => $company, 'owner' => $owner] = $this->onboarding->register($data);

        // Land the owner in the company they just created.
        session()->put(TenantResolver::SESSION_COMPANY, $company->id);
        session()->forget(TenantResolver::SESSION_BRANCH);

        return $owner;
    }

    private function throttle(): void
    {
        $key = 'company-registration:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'company_name' => __('Too many registration attempts. Please try again in :minutes minutes.', [
                    'minutes' => (int) ceil(RateLimiter::availableIn($key) / 60),
                ]),
            ]);
        }

        RateLimiter::hit($key, 3600);
    }
}
