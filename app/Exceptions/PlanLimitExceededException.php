<?php

namespace App\Exceptions;

use App\Enums\PlanLimit;
use Illuminate\Validation\ValidationException;

/**
 * Raised when an action would exceed the company's subscription plan.
 * Extends ValidationException so forms/Livewire show it as a field error.
 */
class PlanLimitExceededException extends ValidationException
{
    public static function for(PlanLimit $limit, ?int $max, string $field = 'plan'): self
    {
        $message = $max === null || $max > 0
            ? __('Your subscription plan allows a maximum of :max :resource. Upgrade your plan to add more.', [
                'max' => $max, 'resource' => __($limit->label()),
            ])
            : __('Your company does not have an active subscription that allows adding :resource.', [
                'resource' => __($limit->label()),
            ]);

        return static::withMessages([$field => $message]);
    }
}
