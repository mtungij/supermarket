<?php

namespace App\Livewire\Purchasing;

use App\Enums\LocationType;
use App\Enums\PurchaseOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Livewire\Concerns\InteractsWithTenant;
use App\Models\ProductUnit;
use App\Models\PurchaseOrder;
use App\Models\StockLocation;
use App\Services\Catalogue\UnitConversionService;
use App\Services\Purchasing\GoodsReceiptService;
use App\Services\Purchasing\ReceiptCalculator;
use App\Support\Decimal;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Receive Purchase (HARDEX-style).
 *
 * - "Receive All" fills every line with its outstanding quantity.
 * - A default receiving location (the branch's Sales Floor) applies to all
 *   lines; each line can override it with another active location of the
 *   order's branch.
 * - Additional (landed) costs are allocated over received base
 *   quantities; the summary recalculates live with the same rules
 *   (ReceiptCalculator) that posting uses.
 * - Posting is one atomic GoodsReceiptService::receive() call, idempotent
 *   per $submissionKey so a double click cannot post twice.
 * - Users with purchases.quick_receive may receive a draft/submitted order;
 *   the approval is recorded as part of posting.
 */
#[Layout('components.layouts.tenant')]
class GoodsReceiptForm extends Component
{
    use InteractsWithTenant;

    #[Locked]
    public string $orderUuid;

    #[Locked]
    public string $submissionKey = '';

    public string $receivedDate = '';

    public string $deliveryNote = '';

    public string $invoiceNumber = '';

    public string $defaultLocation = '';

    public string $notes = '';

    /** @var list<array<string, mixed>> */
    public array $lines = [];

    /** @var list<array{cost_type: string, description: string, amount: string}> */
    public array $costs = [];

    public function mount(PurchaseOrder $order): void
    {
        $this->authorize('purchases.receive');
        $this->ensureBranchAccess($order->branch_id);

        $quick = in_array($order->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Submitted], true)
            && auth()->user()->can('purchases.quick_receive');
        abort_unless($order->status->canReceive() || $quick, 403);

        $this->orderUuid = $order->uuid;
        $this->submissionKey = (string) Str::uuid();
        $this->receivedDate = today($this->company()->timezone)->toDateString();
        // Default destination: the order's branch Sales Floor (by location TYPE), then any
        // sellable location, then the Receiving Area. Users may pick another location.
        $locations = StockLocation::query()->where('branch_id', $order->branch_id)->where('is_active', true)->get(['uuid', 'type', 'is_sellable']);
        $this->defaultLocation = (string) ($locations->firstWhere('type', LocationType::SalesFloor)?->uuid
            ?? $locations->firstWhere('is_sellable', true)?->uuid
            ?? $locations->firstWhere('type', LocationType::ReceivingArea)?->uuid
            ?? $locations->first()?->uuid);

        $this->lines = $order->lines()->with(['product.baseUnit', 'productUnit.unit'])->orderBy('id')->get()
            ->filter(fn ($l) => Decimal::isPositive($l->remainingBase()))
            ->map(fn ($l) => [
                'purchase_order_line_id' => $l->id,
                'product_unit_id' => $l->product_unit_id,
                'quantity' => '',
                'unit_cost' => Decimal::trim(Decimal::round(Decimal::mul($l->netUnitCostBase(), (string) $l->conversion_factor), Decimal::COST)),
                'location' => $this->defaultLocation,
                'batch_number' => '', 'manufactured_on' => '', 'expires_on' => '', 'notes' => '',
            ])->values()->all();
    }

    /** The default location applies to every line (lines can still be overridden afterwards). */
    public function updatedDefaultLocation(string $value): void
    {
        foreach ($this->lines as $i => $line) {
            $this->lines[$i]['location'] = $value;
        }
    }

    /**
     * Fill each line with its outstanding quantity. If the outstanding
     * quantity is not a whole number of the selected unit (e.g. 13 pieces
     * left on a carton-of-24 line) the line switches to the base unit.
     */
    public function receiveAll(): void
    {
        $order = $this->order();

        foreach ($this->lines as $i => $line) {
            $orderLine = $order->lines->firstWhere('id', $line['purchase_order_line_id']);
            $unit = $this->unit($orderLine->product_id, (int) $line['product_unit_id']) ?? $orderLine->productUnit;
            $remaining = $orderLine->remainingBase();
            $inUnit = Decimal::div($remaining, (string) $unit->factor);

            if (Decimal::scaleOf($inUnit) > Decimal::QTY || ($orderLine->product->product_type->requiresWholeBaseQuantities() && ! Decimal::isWhole($inUnit) && ! $unit->is_base)) {
                $base = $this->unit($orderLine->product_id, null, true);
                $this->lines[$i]['unit_cost'] = Decimal::trim(Decimal::round(Decimal::div((string) $line['unit_cost'] ?: '0', (string) $unit->factor), Decimal::COST));
                $this->lines[$i]['product_unit_id'] = $base->id;
                $inUnit = $remaining;
            }

            $this->lines[$i]['quantity'] = Decimal::trim(Decimal::round($inUnit, Decimal::QTY));
        }

        $this->resetErrorBag();
    }

    public function clearQuantities(): void
    {
        foreach ($this->lines as $i => $line) {
            $this->lines[$i]['quantity'] = '';
        }
    }

    public function addCost(): void
    {
        $this->costs[] = ['cost_type' => 'transport', 'description' => '', 'amount' => ''];
    }

    public function removeCost(int $index): void
    {
        unset($this->costs[$index]);
        $this->costs = array_values($this->costs);
    }

    public function save(GoodsReceiptService $receipts): mixed
    {
        $this->authorize('purchases.receive');
        $this->validate([
            'receivedDate' => ['required', 'date', 'before_or_equal:today'],
            'deliveryNote' => ['nullable', 'string', 'max:64'],
            'invoiceNumber' => ['nullable', 'string', 'max:64'],
            'defaultLocation' => ['required', 'uuid'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines.*.quantity' => ['nullable', 'string', 'max:20'],
            'lines.*.unit_cost' => ['nullable', 'string', 'max:20'],
            'lines.*.product_unit_id' => ['required', 'integer'],
            'lines.*.location' => ['nullable', 'uuid'],
            'lines.*.batch_number' => ['nullable', 'string', 'max:64'],
            'lines.*.manufactured_on' => ['nullable', 'date'],
            'lines.*.expires_on' => ['nullable', 'date'],
            'lines.*.notes' => ['nullable', 'string', 'max:500'],
            'costs.*.cost_type' => ['required', 'string'],
            'costs.*.description' => ['nullable', 'string', 'max:150'],
            'costs.*.amount' => ['nullable', 'string', 'max:20'],
        ]);

        $order = $this->order();
        $locations = $this->locations($order)->keyBy('uuid');
        $default = $locations->get($this->defaultLocation);

        if ($default === null) {
            $this->addError('defaultLocation', __('Select a stock location in the order\'s branch.'));

            return null;
        }

        $lines = [];
        foreach ($this->lines as $i => $line) {
            $location = $line['location'] === '' ? $default : $locations->get($line['location']);

            if ($location === null) {
                // Unknown / other-branch / other-company location uuid.
                $this->addError("lines.{$i}.location", __('Select a stock location in the order\'s branch.'));

                return null;
            }

            $lines[] = array_merge($line, ['location_id' => $location->id]);
        }

        $receipt = $receipts->receive($order, [
            'location_id' => $default->id,
            'received_date' => $this->receivedDate,
            'delivery_note_number' => $this->deliveryNote ?: null,
            'supplier_invoice_number' => $this->invoiceNumber ?: null,
            'notes' => $this->notes ?: null,
            'submission_key' => $this->submissionKey,
        ], $lines, $this->actor(), $this->costs);

        session()->flash('status', __('Goods receipt :ref posted. Stock updated.', ['ref' => $receipt->reference]));

        return $this->redirectRoute('purchasing.receipts.show', $receipt);
    }

    private function order(): PurchaseOrder
    {
        $order = PurchaseOrder::query()->where('uuid', $this->orderUuid)
            ->with(['supplier', 'branch', 'creator:id,name', 'lines.product.baseUnit', 'lines.productUnit.unit'])->firstOrFail();
        $this->ensureBranchAccess($order->branch_id);

        return $order;
    }

    private function locations(PurchaseOrder $order)
    {
        return StockLocation::query()->where('branch_id', $order->branch_id)->where('is_active', true)->orderBy('name')->get(['id', 'uuid', 'name']);
    }

    private function unit(int $productId, ?int $unitId, bool $base = false): ?ProductUnit
    {
        return ProductUnit::query()->where('product_id', $productId)->where('is_active', true)
            ->when($base, fn ($q) => $q->where('is_base', true), fn ($q) => $q->whereKey($unitId))->first();
    }

    /** Live per-line figures + summary (display only; posting recomputes everything). */
    private function preview(PurchaseOrder $order, $units): array
    {
        $conversions = app(UnitConversionService::class);
        $calculator = app(ReceiptCalculator::class);
        $rows = [];

        foreach ($this->lines as $i => $line) {
            $orderLine = $order->lines->firstWhere('id', $line['purchase_order_line_id']);
            $unit = $units->firstWhere('id', (int) $line['product_unit_id']);
            $qtyBase = null;
            $error = null;

            if (filled($line['quantity']) && $unit !== null) {
                try {
                    $qtyBase = $conversions->toBase($orderLine->product, $unit, (string) $line['quantity']);
                    if (Decimal::cmp($qtyBase, $orderLine->remainingBase()) > 0) {
                        $error = __('More than the :remaining :unit outstanding.', ['remaining' => Decimal::trim($orderLine->remainingBase()), 'unit' => __($orderLine->product->baseUnit->name)]);
                    }
                } catch (BusinessRuleException $e) {
                    $error = collect($e->errors())->flatten()->first();
                }
            }

            $cost = (string) ($line['unit_cost'] ?: '0');
            $value = $qtyBase !== null && Decimal::isValid($cost) && Decimal::isValid((string) $line['quantity'])
                ? Decimal::round(Decimal::mul((string) $line['quantity'], $cost), Decimal::MONEY) : '0.00';

            $rows[$i] = [
                'order_line' => $orderLine,
                'qty_base' => $error === null ? ($qtyBase ?? '0') : '0',
                'goods_value' => $error === null ? $value : '0.00',
                'location' => $line['location'] ?: $this->defaultLocation,
                'error' => $error,
            ];
        }

        $costTotal = collect($this->costs)->reduce(fn ($c, $x) => Decimal::isValid((string) ($x['amount'] ?: '0')) ? Decimal::add($c, (string) ($x['amount'] ?: '0')) : $c, '0');
        $shares = $calculator->allocate($costTotal, array_map(fn ($r) => $r['qty_base'], $rows));
        foreach ($rows as $i => $row) {
            $rows[$i]['landed'] = $shares[$i] ?? '0.00';
            $rows[$i]['landed_unit'] = Decimal::isPositive($row['qty_base'])
                ? $calculator->landedUnitCost($row['goods_value'], $rows[$i]['landed'], $row['qty_base']) : null;
        }

        $remaining = $order->lines->reduce(fn ($c, $l) => Decimal::add($c, $l->remainingBase()), '0');
        $singleBase = $order->lines->pluck('product.base_unit_id')->unique()->count() === 1;

        return [$rows, $calculator->summary(array_values($rows), $costTotal, $remaining, $singleBase)];
    }

    public function render(): View
    {
        $order = $this->order();
        $units = ProductUnit::query()->whereIn('product_id', $order->lines->pluck('product_id'))->where('is_active', true)->with('unit:id,name')->get();
        [$rows, $summary] = $this->preview($order, $units);

        $ordered = $order->lines->reduce(fn ($c, $l) => Decimal::add($c, (string) $l->quantity_base), '0');
        $previous = $order->lines->reduce(fn ($c, $l) => Decimal::add($c, (string) $l->received_quantity_base), '0');

        return view('livewire.purchasing.goods-receipt-form', [
            'order' => $order,
            'rows' => $rows,
            'summary' => $summary,
            'unitOptions' => $units->groupBy('product_id'),
            'locations' => $this->locations($order),
            'costTypes' => config('inventory.landed_cost_types'),
            'nextReference' => app(GoodsReceiptService::class)->nextReference($this->company()),
            'totals' => ['ordered' => $ordered, 'previous' => $previous, 'remaining' => Decimal::sub($ordered, $previous)],
            'quick' => ! $order->status->canReceive(),
            'currency' => $this->company()->currency,
        ])->title(__('Receive :ref', ['ref' => $order->reference]));
    }
}
