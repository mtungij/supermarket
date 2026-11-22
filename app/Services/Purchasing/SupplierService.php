<?php

namespace App\Services\Purchasing;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Supplier;
use App\Support\NameKey;

class SupplierService
{
    /** @param array{name: string, phone?: ?string, email?: ?string, address?: ?string, tax_id?: ?string, contact_person?: ?string, notes?: ?string, is_active?: bool} $data */
    public function save(Company $company, ?Supplier $supplier, array $data): Supplier
    {
        $supplier ??= new Supplier;
        $supplier->company_id ??= $company->id;

        $name = NameKey::clean($data['name']);
        $key = NameKey::make($name);

        $duplicate = Supplier::forCompany($company)->where('name_key', $key)
            ->when($supplier->exists, fn ($q) => $q->whereKeyNot($supplier->id))->exists();

        if ($duplicate) {
            throw BusinessRuleException::make('name', __('A supplier with this name already exists.'));
        }

        $supplier->fill(collect($data)->only(['phone', 'email', 'address', 'tax_id', 'contact_person', 'notes'])->map(fn ($v) => $v === '' ? null : $v)->all());
        $supplier->forceFill(['name' => $name, 'name_key' => $key, 'is_active' => (bool) ($data['is_active'] ?? true)])->save();

        return $supplier;
    }
}
