<?php

namespace App\Livewire\Purchasing;

use App\Livewire\Concerns\InteractsWithTenant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierTransaction;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.tenant')]
class SupplierShow extends Component
{
    use InteractsWithTenant, WithPagination;

    #[Locked]
    public string $supplierUuid;

    public function mount(Supplier $supplier): void
    {
        $this->authorize('suppliers.view');
        $this->supplierUuid = $supplier->uuid;
    }

    public function render(): View
    {
        $supplier = Supplier::query()->where('uuid', $this->supplierUuid)->firstOrFail();
        $branchIds = $this->accessibleBranches()->pluck('id');

        return view('livewire.purchasing.supplier-show', [
            'supplier' => $supplier,
            'balance' => $supplier->outstandingBalance(),
            'orders' => auth()->user()->can('purchases.view')
                ? PurchaseOrder::query()->where('supplier_id', $supplier->id)->whereIn('branch_id', $branchIds)
                    ->with('branch:id,name')->latest('order_date')->latest('id')->paginate(15)
                : null,
            'transactions' => SupplierTransaction::query()->where('supplier_id', $supplier->id)->latest('occurred_at')->limit(15)->get(),
        ])->title($supplier->name);
    }
}
