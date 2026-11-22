<?php

namespace App\Services\Pos;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;

/** Company-scoped customers. Phone numbers are unique per company (digits compared). */
class CustomerService
{
    public static function phoneKey(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        // Tanzanian local format 07xx... == +2557xx...
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            $digits = '255'.substr($digits, 1);
        }

        return substr($digits, 0, 30);
    }

    /** @param array{name: string, phone?: ?string, email?: ?string, notes?: ?string, is_active?: bool} $data */
    public function save(Company $company, ?Customer $customer, array $data, ?User $actor = null): Customer
    {
        $customer ??= new Customer;
        $customer->company_id ??= $company->id;
        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '' || mb_strlen($name) > 150) {
            throw BusinessRuleException::make('name', __('Enter the customer name.'));
        }

        $key = self::phoneKey($data['phone'] ?? null);
        if ($key !== null && Customer::forCompany($company)->where('phone_key', $key)->when($customer->exists, fn ($q) => $q->whereKeyNot($customer->id))->exists()) {
            throw BusinessRuleException::make('phone', __('A customer with this phone number already exists.'));
        }

        $customer->fill([
            'name' => $name,
            'phone' => filled($data['phone'] ?? null) ? trim($data['phone']) : null,
            'email' => filled($data['email'] ?? null) ? trim($data['email']) : null,
            'notes' => $data['notes'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);
        $customer->forceFill(['phone_key' => $key, 'created_by' => $customer->created_by ?? $actor?->id])->save();

        return $customer;
    }
}
