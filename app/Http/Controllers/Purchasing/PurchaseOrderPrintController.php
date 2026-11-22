<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Support\Tenancy\TenantContext;
use Illuminate\View\View;

/** Printable purchase order to send to the supplier (browser print / PDF). */
class PurchaseOrderPrintController extends Controller
{
    public function __invoke(PurchaseOrder $order, TenantContext $context): View
    {
        $this->authorize('purchases.view');
        abort_unless($context->membership()->canAccessBranch($order->branch_id), 403);

        return view('purchasing.purchase-order-print', [
            'order' => $order->load(['supplier', 'branch', 'creator:id,name', 'approver:id,name', 'lines.product', 'lines.productUnit.unit']),
            'company' => $context->company(),
        ]);
    }
}
