<?php

namespace App\Enums;

enum PosDeviceStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Revoked = 'revoked';

    /** Revoked devices no longer count against the plan's device limit. */
    public function countsTowardLimit(): bool
    {
        return $this !== self::Revoked;
    }
}
