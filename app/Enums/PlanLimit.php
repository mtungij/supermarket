<?php

namespace App\Enums;

/**
 * Countable resources limited by a subscription plan.
 * A NULL limit on the plan means "unlimited".
 */
enum PlanLimit: string
{
    case Branches = 'branches';
    case Staff = 'staff';
    case PosDevices = 'pos_devices';

    public function planColumn(): string
    {
        return 'max_'.$this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::Branches => 'branches',
            self::Staff => 'staff members',
            self::PosDevices => 'POS devices',
        };
    }
}
