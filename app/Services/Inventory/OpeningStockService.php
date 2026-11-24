<?php

namespace App\Services\Inventory;

use App\Enums\OpeningStockStatus;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InvalidDocumentStateException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\OpeningStock;
use App\Models\OpeningStockLine;
use App\Models\OpeningStockRegistration;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Catalogue\PriceService;
use App\Services\Catalogue\UnitConversionService;
use App\Services\Support\Authorizer;
use App\Services\Support\DocumentNumberGenerator;
use App\Services\Support\SubscriptionGuard;
use App\Support\Decimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Opening stock = physical inventory a shop ALREADY OWNED before it started
 * using the system. It is not a purchase (no PO, GRN, supplier, payable or
 * payment), not an adjustment and not a transfer.
 *
 * Eligibility (the "initial stock" rule), per company + branch + product +
 * location:
 *   1. the product has NO stock movement of any other type in that branch
 *      (no receipts, sales, adjustments, transfers, returns), and
 *   2. no opening balance was posted for it at that location before
 *      (opening_stock_registrations, UNIQUE company/location/product).
 * Anything else is a correction and belongs in Stock Adjustments.
 *
 * Valuation: every line needs an explicitly entered, non-negative cost per
 * ENTERED unit. cost per base unit = cost / conversion factor (4 dp);
 * value = base quantity x cost per base unit. A zero cost must be confirmed
 * on the line, explained on the document and posted by a user with
 * inventory.opening.zero_cost. The reference buying price is never used
 * automatically. Posting goes through StockLedger (weighted average,
 * balances, immutable `opening_stock` movements) in one transaction.
 */
class OpeningStockService
{
    public const MAX_LINES = 5000;

    public function __construct(
        private readonly StockLedger $ledger,
        private readonly UnitConversionService $conversions,
        private readonly BatchResolver $batches,
        private readonly DocumentNumberGenerator $numbers,
        private readonly SubscriptionGuard $subscription,
        private readonly Authorizer $authorizer,
    ) {}

    // ---- Eligibility ----------------------------------------------------------

    /**
     * Why each product can NOT receive opening stock at $location (null =
     * eligible). Pass $lock inside a posting transaction for current reads.
     *
     * @param  list<int>  $productIds
     * @return array<int, string>
     */
    public function ineligibility(StockLocation $location, array $productIds, bool $lock = false): array
    {
        if ($productIds === []) {
            return [];
        }

        $reasons = [];
        $opened = OpeningStockRegistration::forCompany($location->company_id)->where('location_id', $location->id)
            ->whereIn('product_id', $productIds)->when($lock, fn ($q) => $q->sharedLock())->get(['product_id', 'opening_stock_id']);
        $refs = OpeningStock::forCompany($location->company_id)->whereIn('id', $opened->pluck('opening_stock_id'))->pluck('reference', 'id');
        foreach ($opened as $row) {
            $reasons[$row->product_id] = __('already has opening stock at :location (:ref). Use Stock Adjustments to correct it.', [
                'location' => __($location->name), 'ref' => $refs[$row->opening_stock_id] ?? '—',
            ]);
        }

        $active = StockMovement::forCompany($location->company_id)->where('branch_id', $location->branch_id)
            ->whereIn('product_id', $productIds)->where('type', '!=', StockMovementType::OpeningStock->value)
            ->when($lock, fn ($q) => $q->sharedLock())
            ->distinct()->pluck('product_id');
        foreach ($active as $id) {
            $reasons[$id] ??= __('already has stock activity in this branch (receipts, sales, adjustments or transfers). Use Stock Adjustments to correct its quantity.');
        }

        return $reasons;
    }

    // ---- Validation (all errors at once: used by the form AND the importer) ---

    /**
     * Validate and normalise lines. Collects EVERY problem as
     * "lines.{i}.{field}" so an import can report all rows in one go.
     *
     * @param  list<array{product_id: int, product_unit_id: int, quantity: string, unit_cost: ?string,
     *     zero_cost_confirmed?: bool, batch_number?: ?string, manufactured_on?: ?string, expires_on?: ?string, import_row?: ?int}>  $lines
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    public function prepareLines(Company $company, StockLocation $location, array $lines): array
    {
        $errors = [];
        $out = [];
        $lines = array_values($lines);

        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('Add at least one product.')]);
        }
        if (count($lines) > self::MAX_LINES) {
            throw ValidationException::withMessages(['lines' => __('An opening-stock document can have at most :max lines.', ['max' => self::MAX_LINES])]);
        }

        $products = Product::forCompany($company)->whereIn('id', array_map(fn ($l) => (int) ($l['product_id'] ?? 0), $lines))->with('baseUnit')->get()->keyBy('id');
        $units = ProductUnit::forCompany($company)->whereIn('id', array_map(fn ($l) => (int) ($l['product_unit_id'] ?? 0), $lines))->get()->keyBy('id');
        $blocked = $this->ineligibility($location, $products->keys()->all());
        $seen = [];

        foreach ($lines as $i => $line) {
            $f = "lines.{$i}";
            $product = $products[(int) ($line['product_id'] ?? 0)] ?? null;

            if ($product === null) {
                $errors["{$f}.product_id"] = __('Select a valid product.');

                continue;
            }
            if (! $product->is_active) {
                $errors["{$f}.product_id"] = __(':product is inactive.', ['product' => $product->name]);

                continue;
            }
            // Already opened here / already trading in this branch: never a second
            // opening balance - the line becomes "Additional stock in" instead.
            $lineType = isset($blocked[$product->id]) ? 'additional' : 'opening';

            $unit = $units[(int) ($line['product_unit_id'] ?? 0)] ?? null;
            if ($unit === null || $unit->product_id !== $product->id || ! $unit->is_active) {
                $errors["{$f}.product_unit_id"] = __('Select a valid unit for :product.', ['product' => $product->name]);

                continue;
            }

            // quantity -> base units (whole pieces for counted products)
            $quantity = trim(str_replace(',', '', (string) ($line['quantity'] ?? '')));
            try {
                if (! Decimal::isValid($quantity) || ! Decimal::isPositive($quantity) || Decimal::scaleOf($quantity) > Decimal::QTY) {
                    throw BusinessRuleException::make('quantity', __('Enter a quantity greater than zero.'));
                }
                $quantityBase = $this->conversions->toBase($product, $unit, $quantity, 'quantity');
            } catch (ValidationException $e) {
                $errors["{$f}.quantity"] = collect($e->errors())->flatten()->first();

                continue;
            }

            // cost: explicit, non-negative; zero only when confirmed
            $cost = trim(str_replace(',', '', (string) ($line['unit_cost'] ?? '')));
            if ($cost === '') {
                $errors["{$f}.unit_cost"] = __('Enter the cost of :product (per :unit).', ['product' => $product->name, 'unit' => __($unit->unit?->name ?? '')]);

                continue;
            }
            if (! Decimal::isValid($cost) || Decimal::isNegative($cost) || Decimal::scaleOf($cost) > Decimal::COST) {
                $errors["{$f}.unit_cost"] = __('Enter a valid cost (0 or more, up to 4 decimals).');

                continue;
            }
            $zero = ! Decimal::isPositive($cost);
            if ($zero && empty($line['zero_cost_confirmed'])) {
                $errors["{$f}.unit_cost"] = __('Cost is zero: enter the real cost, or confirm that this stock has no cost.');

                continue;
            }

            // optional proposed catalogue price (per this unit) - recorded only
            $proposed = trim(str_replace(',', '', (string) ($line['proposed_selling_price'] ?? '')));
            if ($proposed !== '') {
                if (! Decimal::isValid($proposed) || ! Decimal::isPositive($proposed) || Decimal::scaleOf($proposed) > Decimal::MONEY) {
                    $errors["{$f}.proposed_selling_price"] = __('Enter a valid selling price (more than zero, up to 2 decimals).');

                    continue;
                }
                if (! $unit->can_sell) {
                    $errors["{$f}.proposed_selling_price"] = __('This unit is not sold, so it has no selling price.');

                    continue;
                }
            }

            // lots
            $batch = strtoupper(trim((string) ($line['batch_number'] ?? '')));
            $expires = $this->date($line['expires_on'] ?? null);
            $made = $this->date($line['manufactured_on'] ?? null);
            if ($expires === false || $made === false) {
                $errors["{$f}.expires_on"] = __('Enter dates as YYYY-MM-DD.');

                continue;
            }
            if ($product->track_batches && $batch === '') {
                $errors["{$f}.batch_number"] = __(':product requires a batch number.', ['product' => $product->name]);

                continue;
            }
            if ($product->track_expiry && $expires === null) {
                $errors["{$f}.expires_on"] = __(':product requires an expiry date.', ['product' => $product->name]);

                continue;
            }
            if ($batch !== '' && ! preg_match('/^[A-Z0-9\-_.\/]{1,64}$/', $batch)) {
                $errors["{$f}.batch_number"] = __('Batch numbers may contain letters, digits and - _ . / (max 64).');

                continue;
            }
            if ($expires && $made && $made->gt($expires)) {
                $errors["{$f}.manufactured_on"] = __('The manufacturing date must be before the expiry date.');

                continue;
            }
            if ($expires && $expires->lt(today()) && $location->is_sellable) {
                $errors["{$f}.expires_on"] = __('Expired stock cannot be opened into a selling location. Use a non-selling location (e.g. Quarantine).');

                continue;
            }
            if (! $product->requiresBatch()) {
                [$batch, $expires, $made] = ['', null, null];   // lots only for tracked products
            }

            // one line per product (per lot for tracked products)
            $key = $product->id.'|'.($product->requiresBatch() ? ($batch !== '' ? $batch : 'EXP-'.$expires?->format('Ymd')) : '');
            if (isset($seen[$key])) {
                $errors["{$f}.product_id"] = __(':product is listed twice (line :line). Combine the quantities on one line.', ['product' => $product->name, 'line' => $seen[$key] + 1]);

                continue;
            }
            $seen[$key] = $i;

            $costBase = Decimal::round(Decimal::div($cost, (string) $unit->factor), Decimal::COST);
            $out[] = [
                'product_id' => $product->id, 'product_unit_id' => $unit->id, 'conversion_factor' => (string) $unit->factor,
                'quantity' => Decimal::normalize($quantity, Decimal::QTY), 'quantity_base' => $quantityBase,
                'unit_cost' => Decimal::normalize($cost, Decimal::COST), 'unit_cost_base' => $costBase,
                'line_value' => Decimal::round(Decimal::mul($quantityBase, $costBase), Decimal::COST),
                'zero_cost_confirmed' => $zero, 'batch_number' => $batch ?: null,
                'manufactured_on' => $made?->toDateString(), 'expires_on' => $expires?->toDateString(),
                'import_row' => $line['import_row'] ?? null,
                'proposed_selling_price' => $proposed !== '' ? Decimal::normalize($proposed, Decimal::MONEY) : null,
                'line_type' => $lineType,
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    // ---- Drafts ---------------------------------------------------------------

    /**
     * Create or replace a DRAFT. Header: branch_id, location_id, opening_date, notes, zero_cost_reason.
     */
    public function saveDraft(Company $company, ?OpeningStock $draft, array $header, array $lines, User $actor, string $source = 'manual', ?string $filename = null): OpeningStock
    {
        $this->authorizer->permission($actor, 'inventory.opening.manage');
        $this->subscription->ensureWritable($company, 'lines');
        if ($draft !== null && ! OpeningStock::forCompany($company)->whereKey($draft->id)->where('status', OpeningStockStatus::Draft->value)->exists()) {
            $this->lockDraft($draft);   // throws the clear "already posted / cancelled" message
        }

        [$branch, $location, $date] = $this->resolveHeader($company, $header, $actor);
        $prepared = $this->prepareLines($company, $location, $lines);

        return DB::transaction(function () use ($company, $draft, $branch, $location, $date, $header, $prepared, $actor, $source, $filename) {
            if ($draft !== null) {
                $draft = $this->lockDraft($draft);
                $this->authorizer->branch($actor, $draft->branch_id);
                OpeningStockLine::forCompany($company)->where('opening_stock_id', $draft->id)->delete();
                $event = 'updated';
            } else {
                $draft = new OpeningStock;
                $draft->forceFill([
                    'company_id' => $company->id, 'status' => OpeningStockStatus::Draft,
                    'reference' => $this->numbers->next($company, DocumentNumberGenerator::OPENING_STOCK),
                    'source' => $source, 'import_filename' => $filename, 'created_by' => $actor->id,
                ]);
                $event = 'created';
            }

            $draft->forceFill([
                'branch_id' => $branch->id, 'location_id' => $location->id, 'opening_date' => $date->toDateString(),
                'notes' => filled($header['notes'] ?? null) ? trim($header['notes']) : null,
                'zero_cost_reason' => filled($header['zero_cost_reason'] ?? null) ? trim($header['zero_cost_reason']) : null,
                'line_count' => count($prepared),
                'total_quantity_base' => array_reduce($prepared, fn ($c, $l) => Decimal::add($c, $l['quantity_base'], Decimal::QTY), '0'),
                'total_value' => array_reduce($prepared, fn ($c, $l) => Decimal::add($c, $l['line_value'], Decimal::COST), '0'),
                'updated_by' => $actor->id,
            ])->save();

            $now = now();
            foreach (array_chunk($prepared, 500) as $chunk) {
                OpeningStockLine::query()->insert(array_map(fn ($l) => $l + [
                    'company_id' => $company->id, 'opening_stock_id' => $draft->id, 'created_at' => $now, 'updated_at' => $now,
                    'zero_cost_confirmed' => $l['zero_cost_confirmed'] ? 1 : 0,
                ], $chunk));
            }

            $draft->recordEvent($event, $event === 'created' ? null : 'draft', 'draft', $actor,
                __(':lines lines, value :value', ['lines' => count($prepared), 'value' => Decimal::format($draft->total_value)]));

            return $draft;
        });
    }

    /** @return array{0: Branch, 1: StockLocation, 2: Carbon} */
    private function resolveHeader(Company $company, array $header, User $actor): array
    {
        $branch = Branch::forCompany($company)->find($header['branch_id'] ?? 0) ?? throw BusinessRuleException::make('branch', __('Select a valid branch.'));
        $this->authorizer->branch($actor, $branch);
        $location = StockLocation::forCompany($company)->where('branch_id', $branch->id)->where('is_active', true)->find($header['location_id'] ?? 0)
            ?? throw BusinessRuleException::make('location', __('Select an active stock location of :branch.', ['branch' => $branch->name]));
        $date = $this->date($header['opening_date'] ?? null);
        if (! $date) {
            throw BusinessRuleException::make('opening_date', __('Enter the opening date.'));
        }
        if ($date->gt(today())) {
            throw BusinessRuleException::make('opening_date', __('The opening date cannot be in the future.'));
        }

        return [$branch, $location, $date];
    }

    /**
     * Dry run of posting for the confirmation dialog: the SAME checks as
     * saveDraft() + post() (permissions, branch access, location, every
     * line, eligibility, zero-cost rules) without writing anything.
     * Totals are computed here, never in the browser. post() re-checks
     * everything under locks, so this is a convenience, not a safeguard.
     *
     * @return array{branch: string, location: string, sellable: bool, opening_date: string, products: int, lines: int,
     *     quantity: string, value: string, zero_cost_lines: int}
     */
    public function preview(Company $company, ?OpeningStock $document, array $header, array $lines, User $actor): array
    {
        $this->authorizer->permission($actor, 'inventory.opening.post');
        $this->subscription->ensureWritable($company, 'status');
        if ($document !== null && ! OpeningStock::forCompany($company)->whereKey($document->id)->where('status', OpeningStockStatus::Draft->value)->exists()) {
            $this->lockDraft($document);   // clear "already posted / cancelled" message
        }

        [$branch, $location, $date] = $this->resolveHeader($company, $header, $actor);
        $prepared = $this->prepareLines($company, $location, $lines);

        $zero = count(array_filter($prepared, fn ($l) => $l['zero_cost_confirmed']));
        if ($zero > 0 && ! $this->authorizer->can($actor, 'inventory.opening.zero_cost')) {
            throw BusinessRuleException::make('zero_cost_reason', __('This document has zero-cost lines. Only a user allowed to approve zero-cost opening stock can post it.'));
        }
        if ($zero > 0 && blank($header['zero_cost_reason'] ?? null)) {
            throw BusinessRuleException::make('zero_cost_reason', __('Explain why some opening stock has no cost.'));
        }

        return [
            'branch' => $branch->name,
            'location' => __($location->name),
            'sellable' => (bool) $location->is_sellable,
            'opening_date' => $date->toDateString(),
            'products' => count(array_unique(array_column($prepared, 'product_id'))),
            'lines' => count($prepared),
            'quantity' => array_reduce($prepared, fn ($c, $l) => Decimal::add($c, $l['quantity_base'], Decimal::QTY), '0'),
            'value' => array_reduce($prepared, fn ($c, $l) => Decimal::add($c, $l['line_value'], Decimal::COST), '0'),
            'zero_cost_lines' => $zero,
            'additional_lines' => count(array_filter($prepared, fn ($l) => $l['line_type'] === 'additional')),
            'price_changes' => $this->priceChanges($company, $branch, $prepared),
            'can_change_prices' => $this->authorizer->can($actor, 'products.prices.manage'),
        ];
    }

    /**
     * Proposed prices that differ from the CURRENT company-wide price.
     *
     * @return list<array{product: string, unit: string, from: string, to: string}>
     */
    private function priceChanges(Company $company, Branch $branch, array $lines): array
    {
        $changes = [];
        foreach ($lines as $l) {
            if (($l['proposed_selling_price'] ?? null) === null) {
                continue;
            }
            $product = Product::forCompany($company)->find($l['product_id']);
            $unit = ProductUnit::forCompany($company)->with('unit')->find($l['product_unit_id']);
            $current = app(PriceService::class)->resolve($product, $unit)->price;
            if (Decimal::cmp($current, $l['proposed_selling_price']) !== 0) {
                $changes[] = ['product' => $product->name, 'unit' => __($unit->unit->name), 'from' => $current, 'to' => $l['proposed_selling_price']];
            }
        }

        return $changes;
    }

    public function cancel(OpeningStock $draft, User $actor): OpeningStock
    {
        $this->authorizer->permission($actor, 'inventory.opening.manage');

        return DB::transaction(function () use ($draft, $actor) {
            $draft = $this->lockDraft($draft);
            $this->authorizer->branch($actor, $draft->branch_id);
            $draft->forceFill(['status' => OpeningStockStatus::Cancelled, 'cancelled_by' => $actor->id, 'cancelled_at' => now()])->save();
            $draft->recordEvent('cancelled', 'draft', 'cancelled', $actor);

            return $draft;
        });
    }

    // ---- Posting ---------------------------------------------------------------

    /**
     * Post a draft: irreversible. Idempotent against double clicks/retries:
     * the document row is locked and must still be a draft, every product's
     * scope is registered under a UNIQUE index, and StockLedger refuses a
     * second movement for the same source line.
     */
    public function post(OpeningStock $document, User $actor, bool $applyPrices = false): OpeningStock
    {
        $this->authorizer->permission($actor, 'inventory.opening.post');
        if ($applyPrices) {
            $this->authorizer->permission($actor, 'products.prices.manage');
        }

        return DB::transaction(function () use ($document, $actor, $applyPrices) {
            $document = $this->lockDraft($document, posting: true);
            $this->authorizer->branch($actor, $document->branch_id);
            $company = Company::query()->findOrFail($document->company_id);
            $this->subscription->ensureWritable($company, 'status');

            $location = StockLocation::forCompany($company)->where('branch_id', $document->branch_id)->find($document->location_id);
            if ($location === null || ! $location->is_active) {
                throw InvalidDocumentStateException::make('location', __('The stock location is no longer active.'));
            }

            $lines = OpeningStockLine::forCompany($company)->where('opening_stock_id', $document->id)->orderBy('product_id')->orderBy('id')->get();
            if ($lines->isEmpty()) {
                throw InvalidDocumentStateException::make('lines', __('Add at least one product.'));
            }

            $zero = $lines->where('zero_cost_confirmed', true)->isNotEmpty();
            if ($zero && ! $this->authorizer->can($actor, 'inventory.opening.zero_cost')) {
                throw InvalidDocumentStateException::make('zero_cost_reason', __('This document has zero-cost lines. Only a user allowed to approve zero-cost opening stock can post it.'));
            }
            if ($zero && blank($document->zero_cost_reason)) {
                throw InvalidDocumentStateException::make('zero_cost_reason', __('Explain why some opening stock has no cost.'));
            }

            // Serialise with every other posting for these products in this branch
            // (receipts, sales, transfers...), then re-check eligibility with
            // current (locking) reads.
            $productIds = $lines->pluck('product_id')->unique()->sort()->values()->all();
            foreach ($productIds as $id) {
                $this->ledger->lockBranchProduct($company->id, $document->branch_id, $id);
            }
            // Opening lines must STILL be first-time stock (checked under lock). If one
            // became ineligible since the draft was saved we stop instead of silently
            // re-classifying it: saving the draft again turns it into Additional stock in.
            $openingIds = $lines->where('line_type', '!=', 'additional')->pluck('product_id')->unique()->sort()->values()->all();
            $blocked = $this->ineligibility($location, $openingIds, lock: true);
            if ($blocked !== []) {
                $id = array_key_first($blocked);
                throw InvalidDocumentStateException::make('lines', Product::forCompany($company)->whereKey($id)->value('name').' '.$blocked[$id]
                    .' '.__('Save the draft again: it will be entered as Additional stock in.'));
            }
            $productIds = $openingIds;   // only opening products are registered below

            try {
                $productIds === [] ?: OpeningStockRegistration::query()->insert(array_map(fn ($id) => [
                    'company_id' => $company->id, 'branch_id' => $document->branch_id, 'location_id' => $location->id,
                    'product_id' => $id, 'opening_stock_id' => $document->id, 'created_at' => now(),
                ], $productIds));
            } catch (UniqueConstraintViolationException) {
                throw InvalidDocumentStateException::make('lines', __('Opening stock for one of these products was posted at this location in the meantime.'));
            }

            $products = Product::forCompany($company)->whereIn('id', $lines->pluck('product_id')->unique())->get()->keyBy('id');
            $units = ProductUnit::forCompany($company)->whereIn('id', $lines->pluck('product_unit_id'))->get()->keyBy('id');

            foreach ($lines as $line) {
                $product = $products[$line->product_id];
                if (! $product->is_active) {
                    throw InvalidDocumentStateException::make('lines', __(':product is inactive.', ['product' => $product->name]));
                }
                $batch = $this->batches->resolve($product, $line->batch_number, $line->expires_on?->toDateString(), $line->manufactured_on?->toDateString());
                if ($batch?->expires_on !== null && $batch->expires_on->lt(today()) && $location->is_sellable) {
                    throw InvalidDocumentStateException::make('lines', __('Expired stock of :product cannot be opened into a selling location.', ['product' => $product->name]));
                }

                $this->ledger->post(new StockPosting(
                    type: $line->line_type === 'additional' ? StockMovementType::StockIn : StockMovementType::OpeningStock,
                    location: $location,
                    product: $product,
                    quantityBase: (string) $line->quantity_base,
                    source: $line,
                    reference: $document->reference,
                    batch: $batch,
                    unitCost: (string) $line->unit_cost_base,
                    productUnit: $units[$line->product_unit_id] ?? null,
                    enteredQuantity: (string) $line->quantity,
                    conversionFactor: (string) $line->conversion_factor,
                    user: $actor,
                ));

                if ($batch !== null) {
                    $line->forceFill(['batch_id' => $batch->id])->save();
                }
            }

            // Catalogue prices change ONLY when explicitly confirmed (and permitted).
            $pricesChanged = 0;
            if ($applyPrices) {
                foreach ($lines->whereNotNull('proposed_selling_price') as $line) {
                    $unit = $units[$line->product_unit_id];
                    if (Decimal::cmp(app(PriceService::class)->resolve($products[$line->product_id], $unit)->price, (string) $line->proposed_selling_price) !== 0) {
                        app(PriceService::class)->setPrice($products[$line->product_id], $unit, (string) $line->proposed_selling_price, null, $actor,
                            notes: __('From opening stock :ref', ['ref' => $document->reference]));
                        $pricesChanged++;
                        $line->forceFill(['selling_price_applied_at' => now(), 'selling_price_applied_by' => $actor->id])->save();
                    }
                }
            }

            $document->forceFill([
                'status' => OpeningStockStatus::Posted,
                'posted_by' => $actor->id,
                'posted_at' => now(),
                'zero_cost_approved_by' => $zero ? $actor->id : null,
                'updated_by' => $actor->id,
            ])->save();
            $document->recordEvent('posted', 'draft', 'posted', $actor, __(':lines lines, value :value', [
                'lines' => $lines->count(), 'value' => Decimal::format($document->total_value),
            ]).($zero ? ' · '.__('zero-cost lines approved: :reason', ['reason' => $document->zero_cost_reason]) : '')
              .($pricesChanged > 0 ? ' · '.__(':n catalogue selling prices updated', ['n' => $pricesChanged]) : ''));

            return $document;
        }, attempts: 3);
    }

    private function lockDraft(OpeningStock $document, bool $posting = false): OpeningStock
    {
        $document = OpeningStock::forCompany($document->company_id)->lockForUpdate()->findOrFail($document->id);

        if ($document->status !== OpeningStockStatus::Draft) {
            throw InvalidDocumentStateException::make('status', $document->status === OpeningStockStatus::Posted
                ? __('Opening stock :ref is already posted. Posted opening stock cannot be changed; use Stock Adjustments for corrections.', ['ref' => $document->reference])
                : __('Opening stock :ref is :status.', ['ref' => $document->reference, 'status' => __($document->status->label())]));
        }

        return $document;
    }

    /** @return Carbon|null|false null = empty, false = invalid */
    private function date(mixed $value): Carbon|null|false
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);

            return $date && $date->format('Y-m-d') === $value ? $date : false;
        } catch (\Throwable) {
            return false;
        }
    }
}
