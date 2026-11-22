<?php

namespace App\Livewire\Catalogue;

use App\Livewire\Concerns\InteractsWithTenant;
use App\Models\Category;
use App\Services\Catalogue\CategoryService;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.tenant')]
#[Title('Categories')]
class CategoryIndex extends Component
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

    public string $parent = '';

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
        $this->authorize('categories.manage');
        $this->reset(['editing', 'name', 'description', 'parent']);
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function edit(string $uuid): void
    {
        $this->authorize('categories.manage');
        $category = Category::query()->where('uuid', $uuid)->firstOrFail();
        $this->editing = $category->uuid;
        $this->name = $category->name;
        $this->description = (string) $category->description;
        $this->parent = (string) ($category->parent?->uuid ?? '');
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function save(CategoryService $service): void
    {
        $this->authorize('categories.manage');
        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'parent' => ['nullable', 'uuid'],
        ]);

        $parentId = $this->parent === '' ? null
            : (Category::query()->where('uuid', $this->parent)->value('id') ?? abort(422));

        if ($this->editing) {
            $service->update(Category::query()->where('uuid', $this->editing)->firstOrFail(), $this->name, $this->description, $parentId);
        } else {
            $service->create($this->company(), $this->name, $this->description, $parentId);
        }

        $this->showForm = false;
        session()->flash('status', __('Category saved.'));
    }

    public function toggle(string $uuid, CategoryService $service): void
    {
        $this->authorize('categories.manage');
        $category = Category::query()->where('uuid', $uuid)->firstOrFail();
        $service->setActive($category, ! $category->is_active);
    }

    public function delete(string $uuid, CategoryService $service): void
    {
        $this->authorize('categories.manage');
        $service->delete(Category::query()->where('uuid', $uuid)->firstOrFail());
        session()->flash('status', __('Category deleted.'));
    }

    public function render(): View
    {
        return view('livewire.catalogue.category-index', [
            'categories' => Category::query()
                ->with('parent:id,name')
                ->withCount('products')
                ->when($this->search !== '', fn ($q) => $q->where('name', 'like', '%'.addcslashes($this->search, '%_').'%'))
                ->when($this->status !== 'all', fn ($q) => $q->where('is_active', $this->status === 'active'))
                ->orderBy('name')
                ->paginate(20),
            'parents' => Category::query()->where('is_active', true)
                ->when($this->editing, fn ($q) => $q->where('uuid', '!=', $this->editing))
                ->orderBy('name')->get(['uuid', 'name']),
            'canManage' => auth()->user()->can('categories.manage'),
        ]);
    }
}
