<?php

namespace App\Enums;

/**
 * draft → submitted → approved → partially_received → received
 *                ↘ (reject) draft
 * draft|submitted|approved → cancelled   (only while nothing received)
 * partially_received → closed            (remaining quantity abandoned)
 */
enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted, self::Cancelled],
            self::Submitted => [self::Approved, self::Draft, self::Cancelled],
            self::Approved => [self::PartiallyReceived, self::Received, self::Cancelled],
            self::PartiallyReceived => [self::PartiallyReceived, self::Received, self::Closed],
            self::Received, self::Closed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function canReceive(): bool
    {
        return in_array($this, [self::Approved, self::PartiallyReceived], true);
    }

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}
