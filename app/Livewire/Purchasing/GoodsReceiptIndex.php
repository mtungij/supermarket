<?php

namespace App\Livewire\Purchasing;

use App\Livewire\Concerns\InteractsWithTenant;
use App\Models\GoodsReceipt;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.tenant')]
#[Title('Goods received')]
class GoodsReceiptIndex extends Component
{
    use InteractsWithTenant, WithPagination;

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('purchases.view');
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $term = addcslashes(strtoupper(trim($this->search)), '%_');

        return view('livewire.purchasing.goods-receipt-index', [
            'receipts' => GoodsReceipt::query()
                ->whereIn('branch_id', $this->accessibleBranches()->pluck('id'))
                ->with(['supplier:id,name', 'branch:id,name', 'purchaseOrder:id,uuid,reference', 'location:id,name'])
                ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('reference', 'like', "%{$term}%")->orWhere('supplier_reference', 'like', "%{$term}%")))
                ->latest('id')->paginate(20),
        ]);
    }
}
