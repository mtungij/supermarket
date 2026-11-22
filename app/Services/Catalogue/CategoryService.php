<?php

namespace App\Services\Catalogue;

use App\Exceptions\BusinessRuleException;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Support\NameKey;

class CategoryService
{
    public function create(Company $company, string $name, ?string $description = null, ?int $parentId = null): Category
    {
        $category = new Category;
        $category->company_id = $company->id;

        return $this->save($category, $name, $description, $parentId);
    }

    public function update(Category $category, string $name, ?string $description, ?int $parentId): Category
    {
        return $this->save($category, $name, $description, $parentId);
    }

    public function setActive(Category $category, bool $active): void
    {
        $category->forceFill(['is_active' => $active])->save();
    }

    /** Deletion is only possible for unused categories; otherwise deactivate. */
    public function delete(Category $category): void
    {
        if (Product::forCompany($category->company_id)->where('category_id', $category->id)->exists()) {
            throw BusinessRuleException::make('category', __('This category has products. Deactivate it instead of deleting it.'));
        }

        if (Category::forCompany($category->company_id)->where('parent_id', $category->id)->exists()) {
            throw BusinessRuleException::make('category', __('This category has sub-categories. Move or delete them first.'));
        }

        $category->delete();
    }

    private function save(Category $category, string $name, ?string $description, ?int $parentId): Category
    {
        $name = NameKey::clean($name);
        $key = NameKey::make($name);

        if ($name === '' || mb_strlen($name) > 100) {
            throw BusinessRuleException::make('name', __('Enter a category name (max 100 characters).'));
        }

        $duplicate = Category::forCompany($category->company_id)->where('name_key', $key)
            ->when($category->exists, fn ($q) => $q->whereKeyNot($category->id))->exists();

        if ($duplicate) {
            throw BusinessRuleException::make('name', __('A category with this name already exists.'));
        }

        if ($parentId !== null) {
            $parent = Category::forCompany($category->company_id)->find($parentId)
                ?? throw BusinessRuleException::make('parent_id', __('Select a valid parent category.'));

            // Walk up from the proposed parent: reaching this category means a cycle.
            for ($node = $parent, $depth = 0; $node !== null && $depth < 50; $node = $node->parent, $depth++) {
                if ($category->exists && $node->id === $category->id) {
                    throw BusinessRuleException::make('parent_id', __('A category cannot be placed inside itself or its own sub-category.'));
                }
            }
        }

        $category->forceFill([
            'name' => $name,
            'name_key' => $key,
            'description' => $description ?: null,
            'parent_id' => $parentId,
            'is_active' => $category->exists ? $category->is_active : true,
        ])->save();

        return $category;
    }
}
