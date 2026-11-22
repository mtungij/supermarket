<?php

namespace App\Livewire\Inventory;

use App\Livewire\Concerns\InteractsWithTenant;
use App\Models\Category;
use App\Services\Inventory\InventoryQueries;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Stock on hand for the branch chosen in the branch selector (read-only). */
#[Layout('components.layouts.tenant')]
#[Title('Stock on hand')]
class StockIndex extends Component
{
    use InteractsWithTenant, WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $category = '';

    #[Url]
    public string $stock = '';

    #[Url]
    public string $tab = 'stock';

    public function mount(): void
    {
        $this->authorize('inventory.view');
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'category', 'stock'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $branch = $this->currentBranch();
        $queries = app(InventoryQueries::class);
        $categoryId = $this->category !== '' ? Category::query()->where('uuid', $this->category)->value('id') : null;

        return view('livewire.inventory.stock-index', [
            'branch' => $branch,
            'products' => $this->tab === 'stock' ? $queries->branchStock($branch, ['search' => trim($this->search), 'category' => $categoryId, 'stock' => $this->stock]) : null,
            'lowStock' => $queries->lowStock($branch),
            'expiring' => $queries->expiring($branch),
            'valuation' => $queries->valuation($branch),
            'categories' => Category::query()->orderBy('name')->get(['uuid', 'name']),
            'expiryDays' => (int) config('inventory.expiry_alert_days'),
        ]);
    }
}
