<?php

namespace App\Enums;

/**
 * Payment method kinds. All are MANUALLY CONFIRMED by staff: no payment
 * provider is integrated, so Mobile Money / card payments are recorded
 * as confirmed by the cashier, never "verified".
 */
enum PaymentMethodType: string
{
    case Cash = 'cash';
    case MobileMoney = 'mobile_money';
    case Card = 'card';
    case BankTransfer = 'bank_transfer';
    case Other = 'other';
    /** Credit from a sale return, usable only on the linked exchange sale. */
    case ExchangeCredit = 'exchange_credit';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::MobileMoney => 'Mobile Money',
            self::Card => 'Bank / Card',
            self::BankTransfer => 'Bank Transfer',
            self::Other => 'Other',
            self::ExchangeCredit => 'Exchange credit',
        };
    }

    /** Only cash can be over-tendered and give change. */
    public function givesChange(): bool
    {
        return $this === self::Cash;
    }
}
