<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Support\Tenancy\TenantContext;
use Illuminate\View\View;

class GoodsReceiptController extends Controller
{
    public function show(GoodsReceipt $receipt, TenantContext $context): View
    {
        $this->authorize('purchases.view');
        abort_unless($context->membership()->canAccessBranch($receipt->branch_id), 403);

        return view('purchasing.goods-receipt-show', [
            'receipt' => $receipt->load(['supplier', 'branch', 'location', 'purchaseOrder', 'poster:id,name', 'lines.product.baseUnit', 'lines.productUnit.unit', 'lines.batch', 'lines.location', 'costs']),
        ]);
    }
}
