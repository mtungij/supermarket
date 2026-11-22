<?php

namespace App\Services\Support;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;

/**
 * Read-only mode: a company without a usable subscription can still view
 * its catalogue and stock but cannot create or post documents.
 */
class SubscriptionGuard
{
    public function ensureWritable(Company $company, string $field = 'subscription'): void
    {
        if ($company->currentSubscription() === null) {
            throw BusinessRuleException::make($field, __('Your subscription is not active. The catalogue and inventory are read-only until it is renewed.'));
        }
    }
}
