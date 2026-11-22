<?php

namespace App\Livewire\Catalogue;

use App\Livewire\Concerns\InteractsWithTenant;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.tenant')]
#[Title('Products')]
class ProductIndex extends Component
{
    use InteractsWithTenant, WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $category = '';

    #[Url]
    public string $brand = '';

    #[Url]
    public string $status = 'active';

    public function mount(): void
    {
        $this->authorize('products.view');
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'category', 'brand', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $term = addcslashes(trim($this->search), '%_');

        return view('livewire.catalogue.product-index', [
            'products' => Product::query()
                ->with(['category:id,name', 'brand:id,name', 'baseUnit:id,name', 'barcodes' => fn ($q) => $q->where('is_primary', true)])
                ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")
                    ->orWhere('sku', 'like', strtoupper($term).'%')
                    ->orWhereHas('barcodes', fn ($b) => $b->where('barcode', strtoupper($term)))))
                ->when($this->category !== '', fn ($q) => $q->whereHas('category', fn ($c) => $c->where('uuid', $this->category)))
                ->when($this->brand !== '', fn ($q) => $q->whereHas('brand', fn ($c) => $c->where('uuid', $this->brand)))
                ->when($this->status !== 'all', fn ($q) => $q->where('is_active', $this->status === 'active'))
                ->orderBy('name')
                ->paginate(25),
            'categories' => Category::query()->orderBy('name')->get(['uuid', 'name']),
            'brands' => Brand::query()->orderBy('name')->get(['uuid', 'name']),
            'canCreate' => auth()->user()->can('products.create'),
        ]);
    }
}
