<?php

namespace App\Services\Pos;

use App\Enums\PaymentMethodType;
use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\PaymentMethod;
use Illuminate\Support\Str;

class PaymentMethodService
{
    public function save(Company $company, ?PaymentMethod $method, string $name, PaymentMethodType $type, bool $requiresReference, bool $active, int $sort = 10): PaymentMethod
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 60) {
            throw BusinessRuleException::make('name', __('Enter a name (max 60 characters).'));
        }

        if ($method === null && $type === PaymentMethodType::ExchangeCredit) {
            throw BusinessRuleException::make('type', __('Exchange credit is built in.'));
        }

        $method ??= new PaymentMethod;
        if (! $method->exists) {
            $base = Str::slug($name, '_') ?: 'method';
            $code = $base;
            for ($i = 2; PaymentMethod::forCompany($company)->where('code', $code)->exists(); $i++) {
                $code = $base.'_'.$i;
            }
            $method->forceFill(['company_id' => $company->id, 'code' => mb_substr($code, 0, 30), 'type' => $type]);
        }

        if (! $active && $method->exists && $method->type === PaymentMethodType::Cash
            && ! PaymentMethod::forCompany($company)->where('type', PaymentMethodType::Cash->value)->where('is_active', true)->whereKeyNot($method->id)->exists()) {
            throw BusinessRuleException::make('is_active', __('Keep at least one active cash method.'));
        }

        $method->forceFill(['name' => $name, 'requires_reference' => $requiresReference, 'is_active' => $active, 'sort_order' => $sort])->save();

        return $method;
    }
}
