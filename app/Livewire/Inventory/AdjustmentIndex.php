<?php

namespace App\Livewire\Inventory;

use App\Enums\AdjustmentReason;
use App\Enums\StockAdjustmentStatus;
use App\Livewire\Concerns\InteractsWithTenant;
use App\Livewire\Concerns\PicksProducts;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockAdjustment;
use App\Models\StockBatch;
use App\Models\StockLocation;
use App\Services\Inventory\StockAdjustmentService;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Request, approve and reject stock adjustments for the current branch.
 * Stock changes only on approval (inventory.adjust.approve).
 */
#[Layout('components.layouts.tenant')]
#[Title('Stock adjustments')]
class AdjustmentIndex extends Component
{
    use InteractsWithTenant, PicksProducts, WithPagination;

    #[Url]
    public string $status = '';

    public bool $showForm = false;

    public string $location = '';

    public string $reason = 'damaged';

    public string $notes = '';

    public array $lines = [];

    public string $rejection = '';

    public function mount(): void
    {
        $this->authorize('inventory.view');
    }

    public function create(): void
    {
        $this->authorize('inventory.adjust');
        $this->reset(['location', 'notes', 'lines']);
        $this->reason = 'damaged';
        $this->reset(['scan', 'scanMessage']);
        $this->resetErrorBag();
        $this->showForm = true;
        $this->dispatch('scan-focus');
    }

    protected function newLine(Product $product, ProductUnit $unit): array
    {
        return ['product_id' => $product->id, 'product_unit_id' => $unit->id, 'direction' => '', 'batch_id' => ''];
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    public function submit(StockAdjustmentService $service): void
    {
        $this->authorize('inventory.adjust');
        $this->validate([
            'location' => ['required', 'uuid'],
            'reason' => ['required', Rule::enum(AdjustmentReason::class)],
            'notes' => ['required', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.quantity' => ['required', 'string', 'max:20'],
            'lines.*.product_unit_id' => ['required', 'integer'],
            'lines.*.direction' => ['nullable', 'in:in,out'],
            'lines.*.batch_id' => ['nullable', 'integer'],
        ]);

        $location = StockLocation::query()->where('uuid', $this->location)->where('branch_id', $this->currentBranch()->id)->first();

        if ($location === null) {
            $this->addError('location', __('Select a location in the current branch.'));

            return;
        }

        $adjustment = $service->request($location, AdjustmentReason::from($this->reason), $this->notes, array_map(fn ($l) => [
            'product_id' => $l['product_id'], 'product_unit_id' => $l['product_unit_id'], 'quantity' => $l['quantity'],
            'direction' => $l['direction'] ?: null, 'batch_id' => $l['batch_id'] ?: null,
        ], $this->lines), $this->actor());

        $this->showForm = false;
        session()->flash('status', __('Adjustment :ref submitted for approval.', ['ref' => $adjustment->reference]));
    }

    public function approve(string $uuid, StockAdjustmentService $service): void
    {
        $service->approve($this->find($uuid), $this->actor());
        session()->flash('status', __('Adjustment approved and posted.'));
    }

    public function reject(string $uuid, StockAdjustmentService $service): void
    {
        $this->validate(['rejection' => ['required', 'string', 'max:255']]);
        $service->reject($this->find($uuid), $this->actor(), $this->rejection);
        $this->rejection = '';
        session()->flash('status', __('Adjustment rejected.'));
    }

    private function find(string $uuid): StockAdjustment
    {
        $adjustment = StockAdjustment::query()->where('uuid', $uuid)->firstOrFail();
        $this->ensureBranchAccess($adjustment->branch_id);

        return $adjustment;
    }

    /** Batches with stock for a product in the current branch. */
    public function batchesFor(int $productId): Collection
    {
        return StockBatch::query()->where('product_id', $productId)->orderBy('expires_on')->get(['id', 'batch_number', 'expires_on']);
    }

    public function render(): View
    {
        $branch = $this->currentBranch();

        return view('livewire.inventory.adjustment-index', [
            'branch' => $branch,
            'adjustments' => StockAdjustment::query()->where('branch_id', $branch->id)
                ->with(['location:id,name', 'requester:id,name', 'approver:id,name', 'lines.product:id,name,base_unit_id', 'lines.product.baseUnit:id,name', 'lines.batch:id,batch_number'])
                ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
                ->latest('id')->paginate(15),
            'locations' => StockLocation::query()->where('branch_id', $branch->id)->where('is_active', true)->orderBy('name')->get(['uuid', 'name']),
            'reasons' => AdjustmentReason::cases(),
            'statuses' => StockAdjustmentStatus::cases(),
            'products' => Product::query()->whereIn('id', array_column($this->lines, 'product_id'))->get()->keyBy('id'),
            'results' => $this->productResults(),
            'can' => ['request' => auth()->user()->can('inventory.adjust'), 'approve' => auth()->user()->can('inventory.adjust.approve')],
        ]);
    }
}
