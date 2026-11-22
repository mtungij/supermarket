<?php

namespace App\Livewire\Inventory;

use App\Enums\StockMovementType;
use App\Livewire\Concerns\InteractsWithTenant;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Immutable stock ledger for the current branch. */
#[Layout('components.layouts.tenant')]
#[Title('Stock movements')]
class MovementIndex extends Component
{
    use InteractsWithTenant, WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(): void
    {
        $this->authorize('inventory.view');
    }

    public function updating(string $property): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $branch = $this->currentBranch();
        $tz = $this->company()->timezone;
        $term = addcslashes(trim($this->search), '%_');

        return view('livewire.inventory.movement-index', [
            'branch' => $branch,
            'movements' => StockMovement::query()->where('branch_id', $branch->id)
                ->with(['product:id,name,sku,base_unit_id', 'product.baseUnit:id,name', 'location:id,name', 'batch:id,batch_number', 'performer:id,name'])
                ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('reference', 'like', '%'.strtoupper($term).'%')
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$term}%")->orWhere('sku', 'like', strtoupper($term).'%'))))
                ->when($this->type !== '' && StockMovementType::tryFrom($this->type), fn ($q) => $q->where('type', $this->type))
                ->when($this->from !== '', fn ($q) => $q->where('occurred_at', '>=', Carbon::parse($this->from, $tz)->startOfDay()->utc()))
                ->when($this->to !== '', fn ($q) => $q->where('occurred_at', '<=', Carbon::parse($this->to, $tz)->endOfDay()->utc()))
                ->latest('occurred_at')->latest('id')
                ->paginate(30),
            'types' => StockMovementType::cases(),
            'tz' => $tz,
        ]);
    }
}
