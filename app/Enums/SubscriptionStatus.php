<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    /** Statuses under which the company may use (and grow within) its plan. */
    public function isUsable(): bool
    {
        return in_array($this, [self::Trialing, self::Active], true);
    }

    /** @return list<string> */
    public static function usableValues(): array
    {
        return [self::Trialing->value, self::Active->value];
    }
}
