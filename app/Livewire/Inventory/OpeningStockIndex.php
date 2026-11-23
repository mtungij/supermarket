<?php

namespace App\Livewire\Inventory;

use App\Enums\OpeningStockStatus;
use App\Livewire\Concerns\InteractsWithTenant;
use App\Models\OpeningStock;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Opening-stock history for the branches the user may access. */
#[Layout('components.layouts.tenant')]
#[Title('Opening stock')]
class OpeningStockIndex extends Component
{
    use InteractsWithTenant, WithPagination;

    #[Url]
    public string $status = '';

    #[Url]
    public string $branch = '';

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('inventory.view');
    }

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $branches = $this->accessibleBranches();
        $scope = OpeningStock::query()->whereIn('branch_id', $branches->pluck('id'))
            ->when($this->branch !== '', fn ($q) => $q->whereIn('branch_id', $branches->where('uuid', $this->branch)->pluck('id')));

        return view('livewire.inventory.opening-stock-index', [
            'documents' => (clone $scope)
                ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
                ->when(trim($this->search) !== '', fn ($q) => $q->where('reference', 'like', '%'.addcslashes(strtoupper(trim($this->search)), '%_').'%'))
                ->with(['branch:id,name', 'location:id,name', 'creator:id,name', 'poster:id,name'])
                ->latest('id')->paginate(20),
            'totals' => [
                'posted_value' => (string) (clone $scope)->where('status', OpeningStockStatus::Posted->value)->sum('total_value'),
                'posted' => (clone $scope)->where('status', OpeningStockStatus::Posted->value)->count(),
                'drafts' => (clone $scope)->where('status', OpeningStockStatus::Draft->value)->count(),
            ],
            'branches' => $branches,
            'statuses' => OpeningStockStatus::cases(),
            'currency' => $this->company()->currency,
            'canManage' => auth()->user()->can('inventory.opening.manage'),
        ]);
    }
}
