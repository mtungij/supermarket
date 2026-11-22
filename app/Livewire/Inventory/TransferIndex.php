<?php

namespace App\Livewire\Inventory;

use App\Enums\StockTransferStatus;
use App\Livewire\Concerns\InteractsWithTenant;
use App\Livewire\Concerns\PicksProducts;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockBalance;
use App\Models\StockBatch;
use App\Models\StockLocation;
use App\Models\StockTransfer;
use App\Services\Catalogue\UnitConversionService;
use App\Services\Inventory\StockTransferService;
use App\Support\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Transfers from the current branch, and incoming transfers to it.
 * Destination locations may be in any branch of the company; receiving
 * requires access to the destination branch.
 */
#[Layout('components.layouts.tenant')]
#[Title('Stock transfers')]
class TransferIndex extends Component
{
    use InteractsWithTenant, PicksProducts, WithPagination;

    #[Url]
    public string $status = '';

    public bool $showForm = false;

    /** One key per opened form: a double click cannot create two transfers. */
    #[Locked]
    public string $submissionKey = '';

    public string $source = '';

    public string $destination = '';

    public string $notes = '';

    public array $lines = [];

    public function mount(): void
    {
        $this->authorize('inventory.view');
    }

    public function create(): void
    {
        $this->authorize('inventory.transfer');
        $this->reset(['source', 'destination', 'notes', 'lines', 'scan', 'scanMessage']);
        $this->resetErrorBag();
        $this->submissionKey = (string) Str::uuid();
        $this->showForm = true;
        $this->dispatch('scan-focus');
    }

    protected function newLine(Product $product, ProductUnit $unit): array
    {
        return ['product_id' => $product->id, 'product_unit_id' => $unit->id, 'batch_id' => ''];
    }

    /** Scans need a source location and enough stock there for the new quantity. */
    protected function canAdd(Product $product, ProductUnit $unit, string $quantity): ?string
    {
        $source = $this->sourceLocation();

        if ($source === null) {
            return __('Select the source location before scanning.');
        }

        $available = $this->available($product->id, $source->id);
        $wanted = Decimal::mul($quantity, (string) $unit->factor);

        if (Decimal::cmp($wanted, $available) > 0) {
            return __('Only :available :unit of :product available in :location.', [
                'available' => Decimal::trim($available), 'unit' => __($product->baseUnit->name),
                'product' => $product->name, 'location' => $source->name,
            ]);
        }

        return null;
    }

    private function sourceLocation(): ?StockLocation
    {
        return $this->source === '' ? null
            : StockLocation::query()->where('uuid', $this->source)->where('branch_id', $this->currentBranch()->id)->where('is_active', true)->first();
    }

    /** Base quantity on hand at a location (one batch, or all batches). */
    public function available(int $productId, int $locationId, ?int $batchId = null): string
    {
        return (string) (StockBalance::query()->where('location_id', $locationId)->where('product_id', $productId)
            ->when($batchId, fn ($q) => $q->where('batch_id', $batchId))
            ->sum('quantity') ?: '0');
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    public function save(StockTransferService $service, bool $dispatch = false): void
    {
        $this->authorize('inventory.transfer');
        $this->validate([
            'source' => ['required', 'uuid'],
            'destination' => ['required', 'uuid', 'different:source'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.quantity' => ['required', 'string', 'max:20'],
            'lines.*.product_unit_id' => ['required', 'integer'],
            'lines.*.batch_id' => ['nullable', 'integer'],
        ]);

        // Source must be in the CURRENT branch; destination anywhere in this company.
        $source = StockLocation::query()->where('uuid', $this->source)->where('branch_id', $this->currentBranch()->id)->first();
        $destination = StockLocation::query()->where('uuid', $this->destination)->first();

        if ($source === null || $destination === null) {
            $this->addError('destination', __('Select valid source and destination locations.'));

            return;
        }

        // Friendly pre-check; StockLedger re-checks under row locks at dispatch.
        $conversions = app(UnitConversionService::class);
        foreach ($this->lines as $i => $line) {
            $product = Product::query()->find($line['product_id']);
            $unit = ProductUnit::query()->where('product_id', $line['product_id'])->find($line['product_unit_id']);
            if ($product === null || $unit === null) {
                continue; // the service reports invalid products/units
            }
            $base = $conversions->toBase($product, $unit, (string) $line['quantity'], "lines.{$i}.quantity");
            $available = $this->available($product->id, $source->id, $line['batch_id'] ? (int) $line['batch_id'] : null);
            if (Decimal::cmp($base, $available) > 0) {
                $this->addError("lines.{$i}.quantity", __('Only :available :unit available in :location.', [
                    'available' => Decimal::trim($available), 'unit' => __($product->baseUnit->name), 'location' => $source->name,
                ]));

                return;
            }
        }

        $transfer = $service->create($source, $destination, array_map(fn ($l) => [
            'product_id' => $l['product_id'], 'product_unit_id' => $l['product_unit_id'], 'quantity' => $l['quantity'], 'batch_id' => $l['batch_id'] ?: null,
        ], $this->lines), $this->actor(), $this->notes ?: null, $this->submissionKey);

        if ($dispatch) {
            $service->dispatch($transfer, $this->actor());
        }

        $this->showForm = false;
        session()->flash('status', __('Transfer :ref saved.', ['ref' => $transfer->reference]));
    }

    public function act(string $action, string $uuid, StockTransferService $service): void
    {
        $transfer = StockTransfer::query()->where('uuid', $uuid)->firstOrFail();

        match ($action) {
            'dispatch' => $service->dispatch($transfer, $this->actor()),
            'receive' => $service->receive($transfer, $this->actor()),
            'cancel' => $service->cancel($transfer, $this->actor()),
            default => abort(400),
        };

        session()->flash('status', __('Transfer :ref updated.', ['ref' => $transfer->reference]));
    }

    public function batchesFor(int $productId): Collection
    {
        return StockBatch::query()->where('product_id', $productId)->orderBy('expires_on')->get(['id', 'batch_number', 'expires_on']);
    }

    public function render(): View
    {
        $branch = $this->currentBranch();

        return view('livewire.inventory.transfer-index', [
            'branch' => $branch,
            'transfers' => StockTransfer::query()
                ->where(fn ($q) => $q->where('source_branch_id', $branch->id)->orWhere('destination_branch_id', $branch->id))
                ->with(['sourceBranch:id,name', 'destinationBranch:id,name', 'sourceLocation:id,name', 'destinationLocation:id,name', 'lines.product:id,name,base_unit_id', 'lines.product.baseUnit:id,name'])
                ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
                ->latest('id')->paginate(15),
            'sourceLocations' => StockLocation::query()->where('branch_id', $branch->id)->where('is_active', true)->orderBy('name')->get(['uuid', 'name']),
            'destinationLocations' => StockLocation::query()->where('is_active', true)->with('branch:id,name')->orderBy('branch_id')->orderBy('name')->get(['uuid', 'name', 'branch_id']),
            'statuses' => StockTransferStatus::cases(),
            'products' => Product::query()->whereIn('id', array_column($this->lines, 'product_id'))->with('baseUnit:id,name')->get()->keyBy('id'),
            'sourceLocation' => $this->sourceLocation(),
            'results' => $this->productResults(),
            'can' => [
                'transfer' => auth()->user()->can('inventory.transfer'),
                'receive' => auth()->user()->can('inventory.transfer.receive'),
            ],
        ]);
    }
}
