<?php

namespace App\Livewire\Purchasing;

use App\Enums\GoodsReceiptStatus;
use App\Enums\PurchaseOrderStatus;
use App\Livewire\Concerns\InteractsWithTenant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\Purchasing\PurchaseOrderService;
use App\Support\Decimal;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Purchases list (HARDEX-style): number, supplier, branch, date, total,
 * paid, balance, order/receiving status and state-aware actions.
 *
 * Paid / Balance: supplier payments are not implemented yet, so Paid is 0
 * and Balance is the value of goods actually RECEIVED (posted GRNs, incl.
 * tax) minus Paid, i.e. what is currently owed for this purchase. Nothing
 * is ever marked as paid automatically.
 */
#[Layout('components.layouts.tenant')]
#[Title('Purchases')]
class PurchaseOrderIndex extends Component
{
    use InteractsWithTenant, WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $supplier = '';

    public function mount(): void
    {
        $this->authorize('purchases.view');
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'status', 'supplier'], true)) {
            $this->resetPage();
        }
    }

    public function send(string $uuid, PurchaseOrderService $orders): void
    {
        $order = $orders->send($this->find($uuid), $this->actor());
        session()->flash('status', $order->status === PurchaseOrderStatus::Approved
            ? __('Purchase order sent and approved. It can now be received.')
            : __('Purchase order sent for approval.'));
    }

    public function cancel(string $uuid, ?string $reason, PurchaseOrderService $orders): void
    {
        if (trim((string) $reason) === '') {
            $this->addError('status', __('A reason is required to cancel.'));

            return;
        }

        $orders->cancel($this->find($uuid), $this->actor(), mb_substr(trim($reason), 0, 255));
        session()->flash('status', __('Purchase order cancelled.'));
    }

    private function find(string $uuid): PurchaseOrder
    {
        $order = PurchaseOrder::query()->where('uuid', $uuid)->firstOrFail();
        $this->ensureBranchAccess($order->branch_id);

        return $order;
    }

    public function render(): View
    {
        $user = auth()->user();
        $orders = PurchaseOrder::query()
            ->whereIn('branch_id', $this->accessibleBranches()->pluck('id'))
            ->with(['supplier:id,name,uuid', 'branch:id,name'])
            ->withSum(['goodsReceipts as received_value' => fn ($q) => $q->where('status', GoodsReceiptStatus::Posted->value)], 'total')
            ->withSum('lines as ordered_base', 'quantity_base')
            ->withSum('lines as received_base', 'received_quantity_base')
            ->when($this->search !== '', fn ($q) => $q->where('reference', 'like', '%'.addcslashes(strtoupper($this->search), '%_').'%'))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->supplier !== '', fn ($q) => $q->whereHas('supplier', fn ($s) => $s->where('uuid', $this->supplier)))
            ->latest('order_date')->latest('id')
            ->paginate(20);

        $orders->getCollection()->each(function (PurchaseOrder $o) use ($user) {
            $paid = '0.00'; // supplier payments arrive in a later phase
            $o->setAttribute('paid', $paid);
            $o->setAttribute('balance', Decimal::round(Decimal::sub((string) ($o->received_value ?? '0'), $paid), Decimal::MONEY));
            $o->setAttribute('received_percent', Decimal::isPositive((string) $o->ordered_base)
                ? (int) Decimal::round(Decimal::div(Decimal::mul((string) $o->received_base, '100'), (string) $o->ordered_base), 0) : 0);
            $o->setAttribute('actions', [
                'edit' => $o->status->isEditable() && $user->can('purchases.create'),
                'send' => $o->status->isEditable() && $user->can('purchases.create'),
                'receive' => $user->can('purchases.receive') && ($o->status->canReceive()
                    || (in_array($o->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Submitted], true) && $user->can('purchases.quick_receive'))),
                'cancel' => $o->status->canTransitionTo(PurchaseOrderStatus::Cancelled) && ! Decimal::isPositive((string) $o->received_base)
                    && ($user->can('purchases.approve') || ($o->status->isEditable() && $user->can('purchases.create'))),
            ]);
        });

        return view('livewire.purchasing.purchase-order-index', [
            'orders' => $orders,
            'statuses' => PurchaseOrderStatus::cases(),
            'suppliers' => Supplier::query()->orderBy('name')->get(['uuid', 'name']),
            'canCreate' => $user->can('purchases.create'),
        ]);
    }
}
