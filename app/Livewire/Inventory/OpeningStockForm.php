<?php

namespace App\Livewire\Inventory;

use App\Enums\LocationType;
use App\Livewire\Concerns\InteractsWithTenant;
use App\Livewire\Concerns\PicksManyProducts;
use App\Livewire\Concerns\PicksProducts;
use App\Models\Branch;
use App\Models\OpeningStock;
use App\Models\OpeningStockLine;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockBalance;
use App\Models\StockLocation;
use App\Services\Catalogue\PricePrefill;
use App\Services\Catalogue\PriceService;
use App\Services\Inventory\OpeningStockService;
use App\Support\Decimal;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * New / edit opening-stock DRAFT, or read-only view of a posted one.
 * Barcode-first (PicksProducts: same scanner behaviour as purchases,
 * transfers and adjustments). All rules live in OpeningStockService;
 * this component only collects input and shows live totals.
 */
#[Layout('components.layouts.tenant')]
class OpeningStockForm extends Component
{
    use InteractsWithTenant, PicksManyProducts, PicksProducts, WithPagination;

    /** Above this many lines a draft is shown read-only (e.g. big imports). */
    public const EDITABLE_LINES = 300;

    #[Locked]
    public ?string $documentUuid = null;

    public string $branch = '';

    public string $location = '';

    public string $openingDate = '';

    public string $notes = '';

    public string $zeroCostReason = '';

    /** @var list<array{product_id: int, product_unit_id: int, quantity: string, unit_cost: string, zero_cost_confirmed: bool, batch_number: string, manufactured_on: string, expires_on: string}> */
    public array $lines = [];

    /** Confirmation dialogs (x-ui.confirm-dialog). */
    public bool $confirmingPost = false;

    public bool $confirmingCancel = false;

    /** Explicit choice in the post dialog: also change catalogue selling prices. */
    public bool $applyPrices = false;

    /** Server-computed summary shown in the post confirmation. */
    #[Locked]
    public ?array $confirmation = null;

    /** One key per confirmation: a repeated Confirm click is ignored. */
    #[Locked]
    public string $postKey = '';

    public function mount(?OpeningStock $openingStock = null): void
    {
        $this->authorize('inventory.view');

        if ($openingStock?->exists) {
            $this->ensureBranchAccess($openingStock->branch_id);
            $this->documentUuid = $openingStock->uuid;
            $this->fill([
                'branch' => $openingStock->branch->uuid, 'location' => $openingStock->location->uuid,
                'openingDate' => $openingStock->opening_date->toDateString(), 'notes' => (string) $openingStock->notes,
                'zeroCostReason' => (string) $openingStock->zero_cost_reason,
            ]);
            if ($openingStock->isDraft() && $openingStock->line_count <= self::EDITABLE_LINES) {
                $this->lines = OpeningStockLine::query()->where('opening_stock_id', $openingStock->id)->orderBy('id')->get()->map(fn ($l) => [
                    'product_id' => $l->product_id, 'product_unit_id' => $l->product_unit_id,
                    'quantity' => Decimal::trim($l->quantity), 'unit_cost' => Decimal::trim($l->unit_cost),
                    'zero_cost_confirmed' => $l->zero_cost_confirmed, 'batch_number' => (string) $l->batch_number,
                    'manufactured_on' => (string) $l->manufactured_on?->toDateString(), 'expires_on' => (string) $l->expires_on?->toDateString(),
                    'proposed_selling_price' => $l->proposed_selling_price !== null ? Decimal::trim($l->proposed_selling_price) : '',
                ])->all();
            }
        } else {
            $this->authorize('inventory.opening.manage');
            $this->branch = $this->currentBranch()->uuid;
            $this->openingDate = today($this->company()->timezone ?: config('saas.default_timezone'))->toDateString();
            $this->location = $this->defaultLocationUuid();
        }
    }

    /** The branch's Sales Floor (by location TYPE), else a sellable location, else the first one. */
    private function defaultLocationUuid(): string
    {
        $locations = $this->locations();

        return (string) ($locations->firstWhere('type', LocationType::SalesFloor)?->uuid
            ?? $locations->firstWhere('is_sellable', true)?->uuid
            ?? $locations->first()?->uuid);
    }

    private function document(): ?OpeningStock
    {
        return $this->documentUuid ? OpeningStock::query()->where('uuid', $this->documentUuid)->firstOrFail() : null;
    }

    private function branchModel(): ?Branch
    {
        $branch = Branch::query()->where('uuid', $this->branch)->first();

        return $branch && $this->canAccessBranch($branch->id) ? $branch : null;
    }

    private function locations()
    {
        $branch = $this->branchModel();

        return $branch ? StockLocation::query()->where('branch_id', $branch->id)->where('is_active', true)->orderByDesc('is_sellable')->orderBy('name')->get() : collect();
    }

    private function locationModel(): ?StockLocation
    {
        return $this->locations()->firstWhere('uuid', $this->location);
    }

    public function updatedBranch(): void
    {
        $this->location = $this->defaultLocationUuid();
    }

    // ---- lines -----------------------------------------------------------------

    /** New lines come PREFILLED (editable): current/reference cost and the current selling price, per unit. */
    protected function newLine(Product $product, ProductUnit $unit): array
    {
        $prefill = app(PricePrefill::class);

        return ['product_id' => $product->id, 'product_unit_id' => $unit->id,
            'unit_cost' => (string) $prefill->buyingCost($product, $unit, $this->branchModel())[0], 'zero_cost_confirmed' => false,
            'batch_number' => '', 'manufactured_on' => '', 'expires_on' => '',
            'proposed_selling_price' => (string) $prefill->sellingPrice($product, $unit, $this->branchModel())];
    }

    protected function pickerDefaultUnit(Product $product): ?ProductUnit
    {
        return ProductUnit::query()->where('product_id', $product->id)->where('is_active', true)->where('is_base', true)->first();
    }

    /** "In stock" in the picker = what the chosen location holds now. */
    protected function pickerStockScope(): ?array
    {
        $location = $this->locationModel();

        return $location ? ['location', $location->id] : null;
    }

    /**
     * Every active product can be selected. Products already opened here or
     * already trading in the branch are entered as "Additional stock in"
     * (decided again by OpeningStockService on save/post), never refused.
     */
    protected function canAdd(Product $product, ProductUnit $unit, string $quantity): ?string
    {
        return null;
    }

    /**
     * Scan/search again -> +1 on the SAME product/unit line; for lot-tracked
     * products only on a line whose lot is still empty (otherwise a new
     * line, so each lot keeps its own quantity).
     */
    protected function addOrIncrement(Product $product, ProductUnit $unit, string $errorField): bool
    {
        $this->resetErrorBag($errorField);

        foreach ($this->lines as $i => $line) {
            if ((int) $line['product_id'] === $product->id && (int) $line['product_unit_id'] === $unit->id
                && (! $product->requiresBatch() || ($line['batch_number'] === '' && $line['expires_on'] === ''))) {
                $current = Decimal::isValid((string) $line['quantity']) ? (string) $line['quantity'] : '0';
                $this->lines[$i]['quantity'] = Decimal::trim(Decimal::add($current, '1', Decimal::QTY));
                $this->scanMessage = __(':product: quantity :qty', ['product' => $product->name, 'qty' => $this->lines[$i]['quantity']]);

                return true;
            }
        }

        if (($error = $this->canAdd($product, $unit, '1')) !== null) {
            $this->addError($errorField, $error);

            return false;
        }

        $this->lines[] = array_merge($this->newLine($product, $unit), ['quantity' => '1']);
        $this->scanMessage = __('Added :product.', ['product' => $product->name]);

        return true;
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
        $this->forgetErrors('lines');      // indices shifted: old line errors no longer apply
    }

    /**
     * Errors from the last save/check are cleared as soon as the field (or
     * line) they describe changes, so a fixed cost never keeps showing
     * "Enter the cost". The full check runs again on Save / Post.
     */
    public function updated(string $property): void
    {
        // a different unit -> that unit's suggested cost and selling price
        if (preg_match('/^lines\.(\d+)\.product_unit_id$/', $property, $u) && isset($this->lines[(int) $u[1]])) {
            $line = $this->lines[(int) $u[1]];
            $product = Product::query()->find($line['product_id']);
            $unit = ProductUnit::query()->where('product_id', $line['product_id'])->find($line['product_unit_id']);
            if ($product && $unit) {
                $prefill = app(PricePrefill::class);
                $this->lines[(int) $u[1]]['unit_cost'] = (string) $prefill->buyingCost($product, $unit, $this->branchModel())[0];
                $this->lines[(int) $u[1]]['proposed_selling_price'] = (string) $prefill->sellingPrice($product, $unit, $this->branchModel());
            }
        }

        if (preg_match('/^lines\.(\d+)\./', $property, $m)) {
            $this->forgetErrors("lines.{$m[1]}.");
            $this->resetErrorBag(['lines', 'status', 'document']);
        } elseif (in_array($property, ['branch', 'location', 'openingDate', 'zeroCostReason'], true)) {
            $this->resetErrorBag([$property, 'zero_cost_reason', 'status']);
            if ($property !== 'zeroCostReason') {
                $this->forgetErrors('lines');   // eligibility depends on branch/location
            }
        }
    }

    private function forgetErrors(string $prefix): void
    {
        $keys = array_values(array_filter($this->getErrorBag()->keys(), fn ($k) => str_starts_with($k, $prefix)));
        if ($keys !== []) {
            $this->resetErrorBag($keys);
        }
    }

    /** Another lot of the same product (batch/expiry tracked). */
    public function addLot(int $index): void
    {
        if (isset($this->lines[$index])) {
            $this->lines[] = array_merge($this->lines[$index], ['quantity' => '1', 'batch_number' => '', 'manufactured_on' => '', 'expires_on' => '']);
        }
    }

    /** Copy the reference buying price into the cost field: the user still sees and confirms it. */
    public function useReferenceCost(int $index): void
    {
        $line = $this->lines[$index] ?? null;
        $product = $line ? Product::query()->find($line['product_id']) : null;
        $unit = $line ? ProductUnit::query()->find($line['product_unit_id']) : null;

        if ($product?->reference_buying_price !== null && $unit !== null) {
            $this->lines[$index]['unit_cost'] = Decimal::trim(Decimal::round(Decimal::mul((string) $product->reference_buying_price, (string) $unit->factor), Decimal::COST));
        }
    }

    // ---- actions ---------------------------------------------------------------

    private function header(): array
    {
        return [
            'branch_id' => $this->branchModel()?->id,
            'location_id' => $this->locationModel()?->id,
            'opening_date' => $this->openingDate,
            'notes' => $this->notes,
            'zero_cost_reason' => $this->zeroCostReason,
        ];
    }

    private function headerRules(): array
    {
        return [
            'branch' => ['required', 'uuid'], 'location' => ['required', 'uuid'], 'openingDate' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'], 'zeroCostReason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** Re-key service errors onto this form's fields. */
    private function rethrow(ValidationException $e): never
    {
        $map = ['opening_date' => 'openingDate', 'zero_cost_reason' => 'zeroCostReason'];
        throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => [$map[$k] ?? $k => $m])->all());
    }

    /** Lines to check/post: the form's lines, or the stored ones for a large (read-only) draft. */
    private function linesForPosting(?OpeningStock $document): array
    {
        if ($document !== null && $document->line_count > self::EDITABLE_LINES) {
            return OpeningStockLine::query()->where('opening_stock_id', $document->id)->get()->map(fn ($l) => [
                'product_id' => $l->product_id, 'product_unit_id' => $l->product_unit_id, 'quantity' => (string) $l->quantity,
                'unit_cost' => (string) $l->unit_cost, 'zero_cost_confirmed' => $l->zero_cost_confirmed, 'batch_number' => $l->batch_number,
                'manufactured_on' => $l->manufactured_on?->toDateString(), 'expires_on' => $l->expires_on?->toDateString(),
                'proposed_selling_price' => $l->proposed_selling_price !== null ? (string) $l->proposed_selling_price : null,
            ])->all();
        }

        return $this->lines;
    }

    private function persist(OpeningStockService $service): ?OpeningStock
    {
        $document = $this->document();
        $large = $document !== null && $document->line_count > self::EDITABLE_LINES;
        $this->validate($this->headerRules() + ($large ? [] : ['lines' => ['required', 'array', 'min:1']]), ['lines.required' => __('Scan or search for at least one product.')]);

        try {
            if ($document !== null && $document->line_count > self::EDITABLE_LINES) {
                return $document;   // large (imported) draft: lines are not edited here
            }

            return $service->saveDraft($this->company(), $document, $this->header(), $this->lines, $this->actor());
        } catch (ValidationException $e) {
            $this->rethrow($e);
        }
    }

    public function saveDraft(OpeningStockService $service): mixed
    {
        $this->authorize('inventory.opening.manage');
        $document = $this->persist($service);
        session()->flash('status', __('Draft :ref saved. Stock has not changed yet.', ['ref' => $document->reference]));

        return $this->redirectRoute('inventory.opening-stock.show', $document, navigate: false);
    }

    /**
     * Step 1 of posting: validate EVERYTHING on the server (same rules as
     * posting) and open the confirmation dialog with server-computed
     * totals. On any error the dialog stays closed and field errors show.
     */
    public function confirmPost(OpeningStockService $service): void
    {
        $this->authorize('inventory.opening.post');
        $this->resetErrorBag();
        $this->confirmingPost = false;
        $document = $this->document();

        $this->validate($this->headerRules() + ($document?->line_count > self::EDITABLE_LINES ? [] : ['lines' => ['required', 'array', 'min:1']]),
            ['lines.required' => __('Scan or search for at least one product.')]);

        try {
            $this->confirmation = $service->preview($this->company(), $document, $this->header(), $this->linesForPosting($document), $this->actor());
        } catch (ValidationException $e) {
            $this->rethrow($e);
        }

        $this->applyPrices = false;                 // never pre-ticked
        $this->postKey = (string) Str::uuid();
        $this->confirmingPost = true;
    }

    /**
     * Step 2: the dialog's "Confirm posting". The service re-validates and
     * posts in one locked transaction (idempotent per document). The
     * dialog closes only on success (redirect to the posted document).
     */
    public function post(OpeningStockService $service): mixed
    {
        $this->authorize('inventory.opening.post');

        // a repeated click of the same confirmation is ignored
        if ($this->postKey === '' || ! Cache::add('opening-post:'.auth()->id().':'.$this->postKey, true, now()->addMinutes(10))) {
            return null;
        }

        $document = $this->document();
        try {
            if ($document === null || $document->isDraft()) {
                $document = $this->persist($service);
                if ($this->documentUuid === null) {
                    // saved as a draft first: keep working on THAT draft from now on
                    $this->documentUuid = $document->uuid;
                    $this->js('history.replaceState(null, "", '.json_encode(route('inventory.opening-stock.show', $document)).')');
                }
            }
            // What is posted must match what the dialog showed (opening vs additional).
            $additionalNow = OpeningStockLine::query()->where('opening_stock_id', $document->id)->where('line_type', 'additional')->count();
            if ($this->confirmation !== null && $additionalNow !== (int) ($this->confirmation['additional_lines'] ?? 0)) {
                throw ValidationException::withMessages(['lines' => __('Stock changed since you opened this confirmation: :n lines are now Additional stock in. Nothing was posted; check the lines and post again.', ['n' => $additionalNow])]);
            }
            $document = $service->post($document, $this->actor(), $this->applyPrices);
        } catch (ValidationException $e) {
            $this->postKey = '';           // allow a corrected retry via "Post" again
            $this->rethrow($e);
        }

        $this->confirmingPost = false;
        session()->flash('status', __('Opening stock :ref posted: :value added to :location.', [
            'ref' => $document->reference, 'value' => Decimal::format($document->total_value), 'location' => __($document->location->name),
        ]));

        return $this->redirectRoute('inventory.opening-stock.show', $document, navigate: false);
    }

    public function cancelDraft(OpeningStockService $service): mixed
    {
        $this->authorize('inventory.opening.manage');
        $document = $this->document() ?? abort(404);
        $service->cancel($document, $this->actor());
        $this->confirmingCancel = false;
        session()->flash('status', __('Draft :ref cancelled.', ['ref' => $document->reference]));

        return $this->redirectRoute('inventory.opening-stock.index', navigate: false);
    }

    public function render(): View
    {
        $document = $this->document();
        $editable = ($document === null || ($document->isDraft() && $document->line_count <= self::EDITABLE_LINES)) && auth()->user()->can('inventory.opening.manage');

        $products = Product::query()->whereIn('id', array_column($this->lines, 'product_id'))->with(['baseUnit:id,name', 'barcodes' => fn ($b) => $b->orderByDesc('is_primary')])->get()->keyBy('id');
        $units = ProductUnit::query()->whereIn('id', array_column($this->lines, 'product_unit_id'))->with('unit:id,name')->get()->keyBy('id');

        // live totals + read-only reference data (display only; the service recomputes on save/post)
        $location = $this->locationModel();
        $ids = array_column($this->lines, 'product_id');
        $stockHere = $location ? StockBalance::query()->where('location_id', $location->id)->whereIn('product_id', $ids)->groupBy('product_id')
            ->selectRaw('product_id, sum(quantity) as qty')->pluck('qty', 'product_id') : collect();
        $priceService = app(PriceService::class);
        $prefill = app(PricePrefill::class);
        // which lines are "Additional stock in" (product already opened here / trading in the branch)
        $blocked = $location ? app(OpeningStockService::class)->ineligibility($location, array_values(array_unique($ids))) : [];
        $rows = [];
        $totalQty = '0';
        $totalValue = '0';
        foreach ($this->lines as $i => $l) {
            $p = $products[$l['product_id']] ?? null;
            $u = $units[$l['product_unit_id']] ?? null;
            $info = [
                'stock' => Decimal::trim(Decimal::round((string) ($stockHere[$l['product_id']] ?? '0'), Decimal::QTY)),
                'buying' => $p && $u ? $prefill->buyingCost($p, $u, $this->branchModel())[0] : null,
                'buying_source' => $p && $u ? $prefill->buyingCost($p, $u, $this->branchModel())[1] : 'none',
                'selling' => $p && $u && $u->can_sell ? $priceService->resolve($p, $u, $this->branchModel())->price : null,
                'sellable' => (bool) $u?->can_sell,
                'type' => isset($blocked[$l['product_id']]) ? 'additional' : 'opening',
                'type_reason' => $blocked[$l['product_id']] ?? null,
                'unit_name' => $u ? __($u->unit?->name ?? '') : '',
            ];
            $unit = $units[$l['product_unit_id']] ?? null;
            $qty = Decimal::isValid(str_replace(',', '', (string) $l['quantity'])) ? str_replace(',', '', (string) $l['quantity']) : '0';
            $cost = Decimal::isValid(str_replace(',', '', (string) $l['unit_cost'])) ? str_replace(',', '', (string) $l['unit_cost']) : null;
            $qtyBase = $unit ? Decimal::mul($qty, (string) $unit->factor) : '0';
            $value = $cost !== null && $unit ? Decimal::round(Decimal::mul($qtyBase, Decimal::round(Decimal::div($cost, (string) $unit->factor), Decimal::COST)), Decimal::COST) : null;
            $rows[$i] = ['qty_base' => $qtyBase, 'value' => $value, 'zero' => $cost !== null && ! Decimal::isPositive($cost)] + $info;
            $totalQty = Decimal::add($totalQty, $qtyBase);
            $totalValue = $value !== null ? Decimal::add($totalValue, $value) : $totalValue;
        }

        $view = [
            'document' => $document,
            'editable' => $editable,
            'branches' => $this->accessibleBranches(),
            'locations' => $this->locations(),
            'selectedBranch' => $this->branchModel(),
            'selectedLocation' => $this->locationModel(),
            'products' => $products,
            'rows' => $rows,
            'summary' => ['products' => collect($this->lines)->pluck('product_id')->unique()->count(), 'quantity' => $totalQty, 'value' => $totalValue],
            'anyZero' => collect($rows)->contains('zero', true),
            'results' => $editable ? $this->productResults() : collect(),
            'picker' => $editable ? $this->pickerResults() : ['rows' => collect(), 'more' => false, 'stock' => [], 'inList' => [], 'showStock' => false],
            'currency' => $this->company()->currency,
            'can' => [
                'manage' => auth()->user()->can('inventory.opening.manage'),
                'post' => auth()->user()->can('inventory.opening.post'),
                'zero' => auth()->user()->can('inventory.opening.zero_cost'),
            ],
        ];

        if ($document !== null && ! $editable) {
            $view['postedLines'] = OpeningStockLine::query()->where('opening_stock_id', $document->id)
                ->with(['product:id,name,sku,base_unit_id', 'product.baseUnit:id,name', 'productUnit.unit:id,name', 'batch:id,batch_number,expires_on'])
                ->orderBy('id')->paginate(50);
            $view['events'] = $document->events()->with('user:id,name')->get();
        }

        return view('livewire.inventory.opening-stock-form', $view)
            ->title($document ? __('Opening stock :ref', ['ref' => $document->reference]) : __('New opening stock'));
    }
}
