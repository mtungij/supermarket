<?php

namespace App\Livewire\Catalogue;

use App\Livewire\Concerns\InteractsWithTenant;
use App\Models\Brand;
use App\Services\Catalogue\BrandService;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.tenant')]
#[Title('Brands')]
class BrandIndex extends Component
{
    use InteractsWithTenant, WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = 'active';

    public bool $showForm = false;

    #[Locked]
    public ?string $editing = null;

    public string $name = '';

    public string $description = '';

    public bool $isActive = true;

    public function mount(): void
    {
        $this->authorize('products.view');
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function create(): void
    {
        $this->authorize('brands.manage');
        $this->reset(['editing', 'name', 'description']);
        $this->isActive = true;
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function edit(string $uuid): void
    {
        $this->authorize('brands.manage');
        $brand = Brand::query()->where('uuid', $uuid)->firstOrFail();
        [$this->editing, $this->name, $this->description, $this->isActive] = [$brand->uuid, $brand->name, (string) $brand->description, $brand->is_active];
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function save(BrandService $service): void
    {
        $this->authorize('brands.manage');
        $this->validate(['name' => ['required', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:500']]);

        $brand = $this->editing ? Brand::query()->where('uuid', $this->editing)->firstOrFail() : null;
        $service->save($this->company(), $brand, $this->name, $this->description, $this->isActive);

        $this->showForm = false;
        session()->flash('status', __('Brand saved.'));
    }

    public function toggle(string $uuid, BrandService $service): void
    {
        $this->authorize('brands.manage');
        $brand = Brand::query()->where('uuid', $uuid)->firstOrFail();
        $service->save($this->company(), $brand, $brand->name, $brand->description, ! $brand->is_active);
    }

    public function render(): View
    {
        return view('livewire.catalogue.brand-index', [
            'brands' => Brand::query()->withCount('products')
                ->when($this->search !== '', fn ($q) => $q->where('name', 'like', '%'.addcslashes($this->search, '%_').'%'))
                ->when($this->status !== 'all', fn ($q) => $q->where('is_active', $this->status === 'active'))
                ->orderBy('name')->paginate(20),
            'canManage' => auth()->user()->can('brands.manage'),
        ]);
    }
}
