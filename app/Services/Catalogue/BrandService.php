<?php

namespace App\Services\Catalogue;

use App\Exceptions\BusinessRuleException;
use App\Models\Brand;
use App\Models\Company;
use App\Support\NameKey;

class BrandService
{
    public function save(Company $company, ?Brand $brand, string $name, ?string $description, bool $active = true): Brand
    {
        $brand ??= new Brand;
        $brand->company_id ??= $company->id;

        $name = NameKey::clean($name);
        $key = NameKey::make($name);

        if ($name === '' || mb_strlen($name) > 100) {
            throw BusinessRuleException::make('name', __('Enter a brand name (max 100 characters).'));
        }

        $duplicate = Brand::forCompany($company)->where('name_key', $key)
            ->when($brand->exists, fn ($q) => $q->whereKeyNot($brand->id))->exists();

        if ($duplicate) {
            throw BusinessRuleException::make('name', __('A brand with this name already exists.'));
        }

        $brand->forceFill(['name' => $name, 'name_key' => $key, 'description' => $description ?: null, 'is_active' => $active])->save();

        return $brand;
    }
}
