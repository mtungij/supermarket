<?php

namespace App\Livewire\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Livewire\Concerns\InteractsWithTenant;
use App\Livewire\Concerns\PicksProducts;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\Purchasing\PurchaseOrderService;
use App\Support\Decimal;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Create / edit a DRAFT purchase order. Supplier and branch are chosen by
 * UUID and resolved server-side; lines carry product / product-unit ids
 * that PurchaseOrderService re-validates against the company.
 */
#[Layout('components.layouts.tenant')]
class PurchaseOrderForm extends Component
{
    use InteractsWithTenant, PicksProducts;

    #[Locked]
    public ?string $orderUuid = null;

    /** Idempotency key for creation (double-clicked Save cannot create two orders). */
    #[Locked]
    public string $submissionKey = '';

    public string $supplier = '';

    public string $branch = '';

    public string $orderDate = '';

    public string $expectedDate = '';

    public string $notes = '';

    /** @var list<array{product_id: int, product_unit_id: int|string, quantity: string, unit_cost: string, discount_amount: string, tax_rate: string}> */
    public array $lines = [];

    public function mount(?PurchaseOrder $order = null): void
    {
        $this->authorize('purchases.create');
        $this->submissionKey = (string) Str::uuid();
        $this->orderDate = today($this->company()->timezone)->toDateString();
        $this->branch = (string) app(TenantContext::class)->branch()?->uuid;

        if ($order?->exists) {
            $this->ensureBranchAccess($order->branch_id);
            abort_unless($order->status->isEditable(), 403);
            $this->orderUuid = $order->uuid;
            $this->supplier = $order->supplier->uuid;
            $this->branch = $order->branch->uuid;
            $this->orderDate = $order->order_date->toDateString();
            $this->expectedDate = (string) $order->expected_date?->toDateString();
            $this->notes = (string) $order->notes;
            $this->lines = $order->lines->map(fn ($l) => [
                'product_id' => $l->product_id, 'product_unit_id' => $l->product_unit_id,
                'quantity' => Decimal::trim($l->quantity), 'unit_cost' => Decimal::trim($l->unit_cost),
                'discount_amount' => Decimal::trim($l->discount_amount), 'tax_rate' => Decimal::trim($l->tax_rate),
            ])->all();
        }
    }

    protected function newLine(Product $product, ProductUnit $unit): array
    {
        return [
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            // Latest ACTUAL cost (from posted receipts); before the first receipt
            // the optional reference buying price is only a default to edit.
            'unit_cost' => Decimal::trim(Decimal::round(Decimal::mul(
                Decimal::isPositive((string) $product->purchase_cost) ? (string) $product->purchase_cost : (string) ($product->reference_buying_price ?? '0'),
                (string) $unit->factor), Decimal::COST)),
            'discount_amount' => '0',
            'tax_rate' => Decimal::trim($product->tax_class->defaultRate()),
        ];
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    public function save(PurchaseOrderService $orders, bool $submit = false): mixed
    {
        $this->authorize('purchases.create');
        $this->validate([
            'supplier' => ['required', 'uuid'],
            'branch' => ['required', 'uuid'],
            'orderDate' => ['required', 'date'],
            'expectedDate' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.product_unit_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'string', 'max:20'],
            'lines.*.unit_cost' => ['required', 'string', 'max:20'],
            'lines.*.discount_amount' => ['nullable', 'string', 'max:20'],
            'lines.*.tax_rate' => ['nullable', 'string', 'max:6'],
        ]);

        $branch = $this->accessibleBranches()->first(fn (Branch $b) => hash_equals($b->uuid, $this->branch));

        if ($branch === null) {
            $this->addError('branch', __('Select a valid branch.'));

            return null;
        }

        $header = [
            'supplier_id' => Supplier::query()->where('uuid', $this->supplier)->value('id'),
            'branch_id' => $branch->id,
            'order_date' => $this->orderDate,
            'expected_date' => $this->expectedDate ?: null,
            'notes' => $this->notes ?: null,
            'submission_key' => $this->orderUuid ? null : $this->submissionKey,
        ];

        $order = $this->orderUuid
            ? $orders->updateDraft(PurchaseOrder::query()->where('uuid', $this->orderUuid)->firstOrFail(), $header, $this->lines, $this->actor())
            : $orders->createDraft($this->company(), $header, $this->lines, $this->actor());

        if ($submit && $order->status->isEditable()) {
            $order = $orders->send($order, $this->actor());
        }

        session()->flash('status', match (true) {
            ! $submit => __('Draft saved.'),
            $order->status === PurchaseOrderStatus::Approved => __('Purchase order sent and approved. It can now be received.'),
            default => __('Purchase order sent for approval.'),
        });

        return $this->redirectRoute('purchasing.orders.show', $order);
    }

    /**
     * Live document totals for display; PurchaseOrderService recomputes
     * the authoritative values with the same rules when saving.
     *
     * @return array{subtotal: string, discount: string, tax: string, total: string, items: int}
     */
    public function totals(): array
    {
        $t = ['subtotal' => '0', 'discount' => '0', 'tax' => '0', 'total' => '0', 'items' => count($this->lines)];

        foreach ($this->lines as $line) {
            $values = array_map(fn ($k) => (string) (($line[$k] ?? '') ?: '0'), ['quantity', 'unit_cost', 'discount_amount', 'tax_rate']);
            if (in_array(false, array_map(fn ($v) => Decimal::isValid($v), $values), true)) {
                continue;
            }
            [$qty, $cost, $discount, $rate] = $values;
            $sub = Decimal::round(Decimal::mul($qty, $cost), Decimal::MONEY);
            $taxable = Decimal::sub($sub, $discount);
            $tax = Decimal::round(Decimal::div(Decimal::mul($taxable, $rate), '100'), Decimal::MONEY);
            $t['subtotal'] = Decimal::add($t['subtotal'], $sub, Decimal::MONEY);
            $t['discount'] = Decimal::add($t['discount'], $discount, Decimal::MONEY);
            $t['tax'] = Decimal::add($t['tax'], $tax, Decimal::MONEY);
            $t['total'] = Decimal::add($t['total'], Decimal::add($taxable, $tax), Decimal::MONEY);
        }

        return $t;
    }

    /** Live estimate for display only; the service recomputes authoritative totals. */
    public function lineTotal(array $line): string
    {
        foreach (['quantity', 'unit_cost', 'discount_amount', 'tax_rate'] as $key) {
            if (! Decimal::isValid((string) ($line[$key] ?? '0') ?: '0')) {
                return '—';
            }
        }

        $sub = Decimal::mul((string) $line['quantity'], (string) $line['unit_cost']);
        $taxable = Decimal::sub($sub, (string) (($line['discount_amount'] ?? '') ?: '0'));
        $tax = Decimal::div(Decimal::mul($taxable, (string) (($line['tax_rate'] ?? '') ?: '0')), '100');

        return Decimal::format(Decimal::add($taxable, $tax));
    }

    public function render(): View
    {
        return view('livewire.purchasing.purchase-order-form', [
            'suppliers' => Supplier::query()->where('is_active', true)->orderBy('name')->get(['uuid', 'name']),
            'branches' => $this->accessibleBranches(),
            'products' => Product::query()->whereIn('id', array_column($this->lines, 'product_id'))->with('baseUnit:id,name')->get()->keyBy('id'),
            'results' => $this->productResults(),
            'totals' => $this->totals(),
            'currency' => $this->company()->currency,
        ])->title($this->orderUuid ? __('Edit purchase order') : __('New purchase order'));
    }
}
