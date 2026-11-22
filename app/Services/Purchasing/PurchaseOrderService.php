<?php

namespace App\Services\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InvalidDocumentStateException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Catalogue\UnitConversionService;
use App\Services\Support\Authorizer;
use App\Services\Support\DocumentNumberGenerator;
use App\Services\Support\SubscriptionGuard;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Purchase orders never change stock. Stock only moves when a goods
 * receipt (GRN) is posted - see GoodsReceiptService.
 *
 * Line maths (all exact decimals):
 *   line_subtotal = round2(quantity x unit_cost)
 *   taxable       = line_subtotal - discount_amount
 *   tax_amount    = round2(taxable x tax_rate / 100)
 *   line_total    = taxable + tax_amount
 */
class PurchaseOrderService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
        private readonly UnitConversionService $conversions,
        private readonly SubscriptionGuard $subscription,
        private readonly Authorizer $authorizer,
    ) {}

    /**
     * @param  array{supplier_id: int, branch_id: int, order_date: string, expected_date?: ?string, notes?: ?string}  $header
     * @param  list<array{product_id: int, product_unit_id: int, quantity: string, unit_cost: string, discount_amount?: ?string, tax_rate?: ?string}>  $lines
     */
    public function createDraft(Company $company, array $header, array $lines, User $actor): PurchaseOrder
    {
        $this->authorizer->permission($actor, 'purchases.create');
        $this->subscription->ensureWritable($company, 'supplier_id');

        // Idempotent per submission key: a double-clicked "Save" returns the first order.
        $key = $header['submission_key'] ?? null;
        if ($key !== null && ($existing = PurchaseOrder::forCompany($company)->where('submission_key', $key)->first()) !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($company, $header, $lines, $actor, $key) {
            [$branch, $supplier] = $this->header($company, $header, $actor);

            $order = new PurchaseOrder;
            $order->forceFill([
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'supplier_id' => $supplier->id,
                'reference' => $this->numbers->next($company, DocumentNumberGenerator::PURCHASE_ORDER),
                'submission_key' => $key,
                'status' => PurchaseOrderStatus::Draft,
                'order_date' => $header['order_date'],
                'expected_date' => $header['expected_date'] ?? null,
                'currency' => $company->currency,
                'notes' => $header['notes'] ?? null,
                'created_by' => $actor->id,
            ])->save();

            $this->replaceLines($order, $lines);
            $order->recordEvent('created', null, PurchaseOrderStatus::Draft->value, $actor);

            return $order->refresh();
        });
    }

    public function updateDraft(PurchaseOrder $order, array $header, array $lines, User $actor): PurchaseOrder
    {
        $this->authorizer->permission($actor, 'purchases.create');

        return DB::transaction(function () use ($order, $header, $lines, $actor) {
            $order = $this->lock($order);

            if (! $order->status->isEditable()) {
                throw InvalidDocumentStateException::make('status', __('Only draft purchase orders can be edited.'));
            }

            $company = Company::query()->findOrFail($order->company_id);
            [$branch, $supplier] = $this->header($company, $header, $actor);

            $order->forceFill([
                'branch_id' => $branch->id, 'supplier_id' => $supplier->id,
                'order_date' => $header['order_date'], 'expected_date' => $header['expected_date'] ?? null,
                'notes' => $header['notes'] ?? null,
            ])->save();

            $this->replaceLines($order, $lines);
            $order->recordEvent('updated', 'draft', 'draft', $actor);

            return $order->refresh();
        });
    }

    public function submit(PurchaseOrder $order, User $actor): PurchaseOrder
    {
        $this->authorizer->permission($actor, 'purchases.create');

        return $this->transition($order, PurchaseOrderStatus::Submitted, $actor, function (PurchaseOrder $order) use ($actor) {
            if (! $order->lines()->exists()) {
                throw InvalidDocumentStateException::make('lines', __('Add at least one product before submitting.'));
            }

            $order->forceFill(['submitted_by' => $actor->id, 'submitted_at' => now()]);
        });
    }

    /**
     * "Send PO": submit the order, and approve it immediately when the
     * sender may approve (purchases.approve) or the company does not require
     * approval (settings.purchases_require_approval = false). Both steps are
     * recorded in the audit trail; nothing bypasses the state machine.
     */
    public function send(PurchaseOrder $order, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $actor) {
            $order = $this->submit($order, $actor);

            if ($this->authorizer->can($actor, 'purchases.approve')) {
                return $this->approve($order, $actor);
            }

            if (! $this->requiresApproval($order->company_id)) {
                return $this->transition($order, PurchaseOrderStatus::Approved, $actor, function (PurchaseOrder $order) use ($actor) {
                    $order->forceFill(['approved_by' => $actor->id, 'approved_at' => now()]);
                }, __('Approval not required by company settings'));
            }

            return $order;
        });
    }

    /**
     * Quick Receive: approve a draft/submitted order as part of receiving it.
     * Requires the explicitly scoped purchases.quick_receive permission. The
     * submit + approve transitions are recorded like any other approval.
     */
    public function quickApprove(PurchaseOrder $order, User $actor): PurchaseOrder
    {
        $this->authorizer->permission($actor, 'purchases.quick_receive');
        $note = __('Quick receive');

        if ($order->status === PurchaseOrderStatus::Draft) {
            $order = $this->transition($order, PurchaseOrderStatus::Submitted, $actor, function (PurchaseOrder $order) use ($actor) {
                if (! $order->lines()->exists()) {
                    throw InvalidDocumentStateException::make('lines', __('Add at least one product before submitting.'));
                }
                $order->forceFill(['submitted_by' => $actor->id, 'submitted_at' => now()]);
            }, $note);
        }

        return $this->transition($order, PurchaseOrderStatus::Approved, $actor, function (PurchaseOrder $order) use ($actor) {
            $order->forceFill(['approved_by' => $actor->id, 'approved_at' => now()]);
        }, $note);
    }

    public function requiresApproval(int $companyId): bool
    {
        return (bool) data_get(Company::query()->find($companyId)?->settings, 'purchases_require_approval', true);
    }

    public function approve(PurchaseOrder $order, User $actor): PurchaseOrder
    {
        $this->authorizer->permission($actor, 'purchases.approve');

        return $this->transition($order, PurchaseOrderStatus::Approved, $actor, function (PurchaseOrder $order) use ($actor) {
            $order->forceFill(['approved_by' => $actor->id, 'approved_at' => now()]);
        });
    }

    /** Send a submitted order back to draft for corrections. */
    public function reject(PurchaseOrder $order, User $actor, string $reason): PurchaseOrder
    {
        $this->authorizer->permission($actor, 'purchases.approve');

        return $this->transition($order, PurchaseOrderStatus::Draft, $actor, function (PurchaseOrder $order) {
            $order->forceFill(['submitted_by' => null, 'submitted_at' => null]);
        }, $reason);
    }

    public function cancel(PurchaseOrder $order, User $actor, string $reason): PurchaseOrder
    {
        $this->authorizer->permission($actor, in_array($order->status, [PurchaseOrderStatus::Draft], true) ? 'purchases.create' : 'purchases.approve');

        return $this->transition($order, PurchaseOrderStatus::Cancelled, $actor, function (PurchaseOrder $order) use ($actor, $reason) {
            if (PurchaseOrderLine::forCompany($order->company_id)->where('purchase_order_id', $order->id)->where('received_quantity_base', '>', 0)->exists()) {
                throw InvalidDocumentStateException::make('status', __('Goods have already been received on this order.'));
            }

            $order->forceFill(['cancelled_by' => $actor->id, 'cancelled_at' => now(), 'cancellation_reason' => $reason]);
        }, $reason);
    }

    /** Close a partially received order; the outstanding quantity is abandoned. */
    public function close(PurchaseOrder $order, User $actor, string $reason): PurchaseOrder
    {
        $this->authorizer->permission($actor, 'purchases.approve');

        return $this->transition($order, PurchaseOrderStatus::Closed, $actor, function (PurchaseOrder $order) use ($actor) {
            $order->forceFill(['closed_by' => $actor->id, 'closed_at' => now()]);
        }, $reason);
    }

    /**
     * Enforced state machine: locks the row, re-reads the current status
     * and refuses any transition PurchaseOrderStatus does not allow.
     */
    public function transition(PurchaseOrder $order, PurchaseOrderStatus $to, ?User $actor, ?callable $apply = null, ?string $notes = null): PurchaseOrder
    {
        $run = function () use ($order, $to, $actor, $apply, $notes) {
            $order = $this->lock($order);
            $from = $order->status;

            if ($actor !== null) {
                $this->authorizer->branch($actor, $order->branch_id);
            }

            if (! $from->canTransitionTo($to)) {
                throw InvalidDocumentStateException::make('status', __('A :from purchase order cannot be changed to :to.', [
                    'from' => __($from->label()), 'to' => __($to->label()),
                ]));
            }

            if ($apply !== null) {
                $apply($order);
            }

            $order->status = $to;
            $order->save();
            $order->recordEvent($to->value, $from->value, $to->value, $actor, $notes);

            return $order;
        };

        return DB::transactionLevel() > 0 ? $run() : DB::transaction($run);
    }

    public function lock(PurchaseOrder $order): PurchaseOrder
    {
        return PurchaseOrder::forCompany($order->company_id)->lockForUpdate()->findOrFail($order->id);
    }

    /** @return array{0: Branch, 1: Supplier} */
    private function header(Company $company, array $header, User $actor): array
    {
        $branch = Branch::withoutTenancy()->where('company_id', $company->id)->where('is_active', true)->find($header['branch_id'] ?? 0)
            ?? throw BusinessRuleException::make('branch_id', __('Select a valid branch.'));

        $this->authorizer->branch($actor, $branch);

        // Never accept a supplier from another company (or an inactive one).
        $supplier = Supplier::forCompany($company)->where('is_active', true)->find($header['supplier_id'] ?? 0)
            ?? throw BusinessRuleException::make('supplier_id', __('Select a valid supplier.'));

        if (filled($header['expected_date'] ?? null) && $header['expected_date'] < $header['order_date']) {
            throw BusinessRuleException::make('expected_date', __('The expected delivery date cannot be before the order date.'));
        }

        return [$branch, $supplier];
    }

    private function replaceLines(PurchaseOrder $order, array $lines): void
    {
        if ($lines === []) {
            throw BusinessRuleException::make('lines', __('Add at least one product.'));
        }

        PurchaseOrderLine::forCompany($order->company_id)->where('purchase_order_id', $order->id)->delete();

        $totals = ['subtotal' => '0', 'discount_total' => '0', 'tax_total' => '0', 'total' => '0'];

        foreach (array_values($lines) as $i => $line) {
            $field = "lines.{$i}";

            $product = Product::forCompany($order->company_id)->where('is_active', true)->find($line['product_id'] ?? 0)
                ?? throw BusinessRuleException::make("{$field}.product_id", __('Select a valid product.'));

            $unit = ProductUnit::forCompany($order->company_id)->where('product_id', $product->id)
                ->where('is_active', true)->where('can_purchase', true)->find($line['product_unit_id'] ?? 0)
                ?? throw BusinessRuleException::make("{$field}.product_unit_id", __('Select a purchasable unit for :product.', ['product' => $product->name]));

            $quantityBase = $this->conversions->toBase($product, $unit, (string) ($line['quantity'] ?? ''), "{$field}.quantity");
            $quantity = Decimal::normalize((string) $line['quantity'], Decimal::QTY);
            $unitCost = $this->decimal($line['unit_cost'] ?? null, Decimal::COST, "{$field}.unit_cost");
            $discount = $this->decimal($line['discount_amount'] ?? '0', Decimal::MONEY, "{$field}.discount_amount");
            $taxRate = $this->decimal($line['tax_rate'] ?? '0', Decimal::MONEY, "{$field}.tax_rate");

            if (Decimal::cmp($taxRate, '100') > 0) {
                throw BusinessRuleException::make("{$field}.tax_rate", __('The tax rate cannot exceed 100%.'));
            }

            $subtotal = Decimal::round(Decimal::mul($quantity, $unitCost), Decimal::MONEY);

            if (Decimal::cmp($discount, $subtotal) > 0) {
                throw BusinessRuleException::make("{$field}.discount_amount", __('The discount cannot exceed the line amount.'));
            }

            $taxable = Decimal::sub($subtotal, $discount, Decimal::MONEY);
            $tax = Decimal::round(Decimal::div(Decimal::mul($taxable, $taxRate), '100'), Decimal::MONEY);
            $lineTotal = Decimal::add($taxable, $tax, Decimal::MONEY);

            $record = new PurchaseOrderLine;
            $record->forceFill([
                'company_id' => $order->company_id,
                'purchase_order_id' => $order->id,
                'product_id' => $product->id,
                'product_unit_id' => $unit->id,
                'conversion_factor' => $unit->factor,
                'quantity' => $quantity,
                'quantity_base' => $quantityBase,
                'unit_cost' => $unitCost,
                'discount_amount' => $discount,
                'tax_rate' => $taxRate,
                'tax_amount' => $tax,
                'line_subtotal' => $subtotal,
                'line_total' => $lineTotal,
                'received_quantity_base' => '0',
            ])->save();

            $totals['subtotal'] = Decimal::add($totals['subtotal'], $subtotal, Decimal::MONEY);
            $totals['discount_total'] = Decimal::add($totals['discount_total'], $discount, Decimal::MONEY);
            $totals['tax_total'] = Decimal::add($totals['tax_total'], $tax, Decimal::MONEY);
            $totals['total'] = Decimal::add($totals['total'], $lineTotal, Decimal::MONEY);
        }

        $order->forceFill($totals)->save();
    }

    private function decimal(mixed $value, int $scale, string $field): string
    {
        $value = str_replace(',', '', trim((string) ($value ?? '')));
        $value = $value === '' ? '0' : $value;

        if (! Decimal::isValid($value) || Decimal::isNegative($value) || Decimal::scaleOf($value) > $scale) {
            throw BusinessRuleException::make($field, __('Enter a valid non-negative number (max :scale decimals).', ['scale' => $scale]));
        }

        return Decimal::normalize($value, $scale);
    }
}
