<?php

namespace App\Livewire\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Livewire\Concerns\InteractsWithTenant;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Services\Purchasing\PurchaseOrderService;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('components.layouts.tenant')]
class PurchaseOrderShow extends Component
{
    use InteractsWithTenant;

    #[Locked]
    public string $orderUuid;

    public string $reason = '';

    public function mount(PurchaseOrder $order): void
    {
        $this->authorize('purchases.view');
        $this->ensureBranchAccess($order->branch_id);
        $this->orderUuid = $order->uuid;
    }

    private function order(): PurchaseOrder
    {
        $order = PurchaseOrder::query()->where('uuid', $this->orderUuid)->firstOrFail();
        $this->ensureBranchAccess($order->branch_id);

        return $order;
    }

    /** Status actions; the service enforces permissions and the state machine. */
    public function act(string $action, PurchaseOrderService $orders): void
    {
        $order = $this->order();
        $actor = $this->actor();

        if (in_array($action, ['reject', 'cancel', 'close'], true)) {
            $this->validate(['reason' => ['required', 'string', 'max:255']]);
        }

        match ($action) {
            'submit', 'send' => $orders->send($order, $actor),
            'approve' => $orders->approve($order, $actor),
            'reject' => $orders->reject($order, $actor, $this->reason),
            'cancel' => $orders->cancel($order, $actor, $this->reason),
            'close' => $orders->close($order, $actor, $this->reason),
            default => abort(400),
        };

        $this->reason = '';
        session()->flash('status', __('Purchase order updated.'));
    }

    public function render(): View
    {
        $order = $this->order()->load(['supplier', 'branch', 'creator:id,name', 'approver:id,name', 'lines.product.baseUnit', 'lines.productUnit.unit']);
        $user = auth()->user();

        return view('livewire.purchasing.purchase-order-show', [
            'order' => $order,
            'receipts' => GoodsReceipt::query()->where('purchase_order_id', $order->id)->with('poster:id,name')->latest('id')->get(),
            'events' => $order->events()->with('user:id,name')->get(),
            'can' => [
                'edit' => $order->status->isEditable() && $user->can('purchases.create'),
                'submit' => $order->status->canTransitionTo(PurchaseOrderStatus::Submitted) && $user->can('purchases.create'),
                'approve' => $order->status === PurchaseOrderStatus::Submitted && $user->can('purchases.approve'),
                'receive' => $user->can('purchases.receive') && ($order->status->canReceive()
                    || (in_array($order->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Submitted], true) && $user->can('purchases.quick_receive'))),
                'quick' => ! $order->status->canReceive() && $user->can('purchases.quick_receive'),
                'cancel' => $order->status->canTransitionTo(PurchaseOrderStatus::Cancelled) && ($user->can('purchases.approve') || ($order->status->isEditable() && $user->can('purchases.create'))),
                'close' => $order->status === PurchaseOrderStatus::PartiallyReceived && $user->can('purchases.approve'),
            ],
        ])->title($order->reference);
    }
}
