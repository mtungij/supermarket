<?php

namespace Tests\Feature\Inventory;

use App\Enums\BarcodeType;
use App\Enums\LocationType;
use App\Enums\StockMovementType;
use App\Enums\SystemRole;
use App\Exceptions\InvalidDocumentStateException;
use App\Livewire\Inventory\OpeningStockForm;
use App\Livewire\Inventory\OpeningStockImport;
use App\Models\Branch;
use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\InventoryCost;
use App\Models\OpeningStock;
use App\Models\OpeningStockRegistration;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Sale;
use App\Models\StockBalance;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\BranchService;
use App\Services\Catalogue\BarcodeService;
use App\Services\Inventory\OpeningStockService;
use App\Services\Pos\PosCatalog;
use App\Support\Decimal;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\CreatesInventory;
use Tests\Concerns\CreatesPos;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;
use ZipArchive;

/**
 * Opening stock: initial inventory owned before using the system.
 * Never a purchase (no PO/GRN/supplier), never an adjustment.
 */
class OpeningStockTest extends TestCase
{
    use CreatesInventory, CreatesPos, CreatesTenants, RefreshDatabase;

    protected bool $seed = true;

    private Company $company;

    private User $owner;

    private Branch $hq;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany();
        $this->owner = $this->createOwner($this->company);
        $this->actingAsTenant($this->owner, $this->company);
        $this->hq = $this->headOffice($this->company);
    }

    private function loc(LocationType $type = LocationType::SalesFloor): StockLocation
    {
        return $this->location($this->hq, $type);
    }

    private function service(): OpeningStockService
    {
        return app(OpeningStockService::class);
    }

    private function draft(array $lines, LocationType $type = LocationType::SalesFloor, ?User $actor = null, array $header = []): OpeningStock
    {
        return $this->service()->saveDraft($this->company, null, $header + [
            'branch_id' => $this->hq->id, 'location_id' => $this->loc($type)->id, 'opening_date' => today()->toDateString(), 'notes' => 'Go-live count',
        ], $lines, $actor ?? $this->owner);
    }

    private function line(Product $p, string $qty, ?string $cost, array $extra = []): array
    {
        return $extra + ['product_id' => $p->id, 'product_unit_id' => $this->baseUnitOf($p)->id, 'quantity' => $qty, 'unit_cost' => $cost];
    }

    private function onHandAt(Product $p, LocationType $type = LocationType::SalesFloor): string
    {
        return (string) (StockBalance::forCompany($this->company)->where('location_id', $this->loc($type)->id)->where('product_id', $p->id)->sum('quantity') ?: '0');
    }

    private function avg(Product $p): string
    {
        return (string) InventoryCost::forCompany($this->company)->where('branch_id', $this->hq->id)->where('product_id', $p->id)->value('average_cost');
    }

    private function noPurchasingRecords(): void
    {
        $this->assertSame(0, PurchaseOrder::forCompany($this->company)->count());
        $this->assertSame(0, GoodsReceipt::forCompany($this->company)->count());
        $this->assertSame(0, Supplier::forCompany($this->company)->count());
        $this->assertSame(0, Sale::forCompany($this->company)->count());
    }

    // ---- acceptance example ---------------------------------------------------

    public function test_acceptance_drs_medicated_20_pieces_at_500(): void
    {
        $drs = $this->makeProduct($this->company, $this->owner, ['name' => 'DRS MEDICATED', 'barcode' => '6203013430060', 'reference_buying_price' => '450']);

        $draft = $this->draft([$this->line($drs, '20', '500')]);
        $this->assertSame('draft', $draft->status->value);
        $this->assertSame(0, StockMovement::forCompany($this->company)->count());          // drafts never touch stock
        $this->assertMatchesRegularExpression('/^OPS-\d{6}$/', $draft->reference);

        $posted = $this->service()->post($draft, $this->owner);

        $this->assertSame('posted', $posted->status->value);
        $this->assertSame($this->owner->id, $posted->posted_by);
        $this->assertSame('20.0000', $this->onHandAt($drs));
        $this->assertSame('500.0000', $this->avg($drs));
        $this->assertSame('10000.0000', $posted->total_value);
        $movement = StockMovement::forCompany($this->company)->sole();
        $this->assertSame([StockMovementType::OpeningStock, '20.0000', '500.0000', '10000.0000', $draft->reference],
            [$movement->type, $movement->quantity, $movement->unit_cost, $movement->total_cost, $movement->reference]);
        $this->assertSame('20', Decimal::trim(app(PosCatalog::class)->available($this->loc(), $drs->id)));   // POS sells it now
        $this->noPurchasingRecords();

        // a duplicate post request does not add stock again
        try {
            $this->service()->post($draft, $this->owner);
            $this->fail('Posted twice');
        } catch (InvalidDocumentStateException $e) {
            $this->assertStringContainsString('already posted', $e->getMessage());
        }
        $this->assertSame('20.0000', $this->onHandAt($drs));
        $this->assertSame(1, StockMovement::forCompany($this->company)->count());
    }

    // ---- valuation ---------------------------------------------------------------

    public function test_unit_conversion_and_weighted_average_across_locations(): void
    {
        $juice = $this->makeProduct($this->company, $this->owner, ['name' => 'JUICE']);
        $carton = $this->packaging($juice, 'ctn', '24');

        // 2 cartons @ 24,000 per carton -> 48 pieces @ 1,000 per piece
        $doc = $this->draft([['product_id' => $juice->id, 'product_unit_id' => $carton->id, 'quantity' => '2', 'unit_cost' => '24,000']], LocationType::MainStore);
        $line = $doc->lines()->sole();
        $this->assertSame(['2.0000', '48.0000', '24000.0000', '1000.0000', '48000.0000'], [$line->quantity, $line->quantity_base, $line->unit_cost, $line->unit_cost_base, $line->line_value]);
        $this->service()->post($doc, $this->owner);
        $this->assertSame('1000.0000', $this->avg($juice));
        $this->assertSame('0', app(PosCatalog::class)->available($this->loc(), $juice->id));        // Main Store is not sellable

        // a second location of the same branch (still no other activity): weighted
        $this->service()->post($this->draft([$this->line($juice, '16', '1400')]), $this->owner);
        // (48*1000 + 16*1400) / 64 = 1100
        $this->assertSame('1100.0000', $this->avg($juice));
        $this->assertSame('16.0000', $this->onHandAt($juice));
    }

    public function test_cost_is_validated_and_prefilled_visibly_in_the_form(): void
    {
        $soap = $this->makeProduct($this->company, $this->owner, ['name' => 'SOAP', 'reference_buying_price' => '450']);

        foreach ([null, '', '-5', 'abc', '1.23456'] as $bad) {
            try {
                $this->draft([$this->line($soap, '5', $bad)]);
                $this->fail('accepted cost '.var_export($bad, true));
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('lines.0.unit_cost', $e->errors());
            }
        }
        $this->assertSame(0, OpeningStock::forCompany($this->company)->count());

        // the form PREFILLS an editable cost: last purchase cost, else the reference buying price
        $form = Livewire::test(OpeningStockForm::class)->call('pickProduct', $soap->id);
        $this->assertSame('1000', $form->get('lines')[0]['unit_cost']);                         // purchase_cost of the test product
        $form->assertSee('Suggested: last purchase cost');
        $refOnly = $this->makeProduct($this->company, $this->owner, ['name' => 'REF ONLY', 'purchase_cost' => '0', 'reference_buying_price' => '450']);
        $none = $this->makeProduct($this->company, $this->owner, ['name' => 'NO COST', 'purchase_cost' => '0']);
        $form->call('pickProduct', $refOnly->id)->call('pickProduct', $none->id)->assertSee('Suggested: reference buying price');
        $this->assertSame(['1000', '450', ''], array_column($form->get('lines'), 'unit_cost'));
        $form->set('lines.0.unit_cost', '980')->call('saveDraft')->assertHasErrors('lines.2.unit_cost');   // no cost -> blank and required
    }

    public function test_zero_cost_needs_confirmation_reason_and_elevated_permission(): void
    {
        $sample = $this->makeProduct($this->company, $this->owner, ['name' => 'FREE SAMPLE']);

        try {
            $this->draft([$this->line($sample, '10', '0')]);
            $this->fail('zero cost accepted without confirmation');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('confirm', $e->errors()['lines.0.unit_cost'][0]);
        }

        // a store keeper may prepare it...
        $keeper = $this->addMember($this->company, SystemRole::StoreKeeper, [$this->hq]);
        $this->actingAsTenant($keeper, $this->company);
        $doc = $this->draft([$this->line($sample, '10', '0', ['zero_cost_confirmed' => true])], actor: $keeper);
        $this->assertTrue($doc->lines()->sole()->zero_cost_confirmed);
        // ...but not post it
        $this->expectsAuthorization(fn () => $this->service()->post($doc, $keeper));

        // a branch manager may post opening stock, but not zero-cost lines
        $manager = $this->addMember($this->company, SystemRole::BranchManager, [$this->hq]);
        $this->actingAsTenant($manager, $this->company);
        $this->assertThrowsMessage(fn () => $this->service()->post($doc, $manager), 'zero-cost');

        // the owner may, with a reason
        $this->actingAsTenant($this->owner, $this->company);
        $this->assertThrowsMessage(fn () => $this->service()->post($doc, $this->owner), 'Explain why');
        $doc = $this->service()->saveDraft($this->company, $doc, ['branch_id' => $this->hq->id, 'location_id' => $this->loc()->id,
            'opening_date' => today()->toDateString(), 'zero_cost_reason' => 'Supplier samples'], [$this->line($sample, '10', '0', ['zero_cost_confirmed' => true])], $this->owner);
        $posted = $this->service()->post($doc, $this->owner);
        $this->assertSame([$this->owner->id, 'Supplier samples'], [$posted->zero_cost_approved_by, $posted->zero_cost_reason]);
        $this->assertSame('10.0000', $this->onHandAt($sample));
        $this->assertSame('0.0000', $this->avg($sample));
    }

    // ---- batches & expiry ----------------------------------------------------------

    public function test_batch_and_expiry_rules_and_expired_stock_never_sellable(): void
    {
        $milk = $this->makeProduct($this->company, $this->owner, ['name' => 'MILK', 'track_batches' => true, 'track_expiry' => true]);

        $this->assertThrowsMessage(fn () => $this->draft([$this->line($milk, '5', '900')]), 'requires a batch number');
        $this->assertThrowsMessage(fn () => $this->draft([$this->line($milk, '5', '900', ['batch_number' => 'L1'])]), 'requires an expiry date');
        $this->assertThrowsMessage(fn () => $this->draft([$this->line($milk, '5', '900', ['batch_number' => 'OLD', 'expires_on' => today()->subDay()->toDateString()])]), 'Expired stock');
        $this->assertThrowsMessage(fn () => $this->draft([
            $this->line($milk, '5', '900', ['batch_number' => 'L1', 'expires_on' => today()->addMonth()->toDateString()]),
            $this->line($milk, '2', '900', ['batch_number' => 'l1', 'expires_on' => today()->addMonth()->toDateString()]),
        ]), 'listed twice');

        // two lots of the same product -> separate lot balances, FEFO order kept
        $doc = $this->draft([
            $this->line($milk, '5', '900', ['batch_number' => 'L2', 'expires_on' => today()->addMonths(2)->toDateString()]),
            $this->line($milk, '3', '900', ['batch_number' => 'L1', 'expires_on' => today()->addDays(10)->toDateString(), 'manufactured_on' => today()->subMonth()->toDateString()]),
        ]);
        $this->service()->post($doc, $this->owner);
        $balances = app(PosCatalog::class)->sellableBalances($this->loc(), $milk->id);
        $this->assertSame(['3.0000', '5.0000'], $balances->pluck('quantity')->all());                // earliest expiry first
        $this->assertSame(['L1', 'L2'], $balances->map(fn ($b) => $b->batch->batch_number)->all());

        // expired lots may only go to a NON-selling location, and are never sellable
        $cheese = $this->makeProduct($this->company, $this->owner, ['name' => 'CHEESE', 'track_expiry' => true]);
        $this->service()->post($this->draft([$this->line($cheese, '4', '1000', ['expires_on' => today()->subDays(3)->toDateString()])], LocationType::Quarantine), $this->owner);
        $this->assertSame('4.0000', $this->onHandAt($cheese, LocationType::Quarantine));
        $this->assertSame('0', app(PosCatalog::class)->available($this->loc(LocationType::Quarantine), $cheese->id));
    }

    // ---- duplicate / eligibility ---------------------------------------------------

    public function test_existing_products_become_additional_stock_in_never_a_second_opening_balance(): void
    {
        $types = fn (Product $p) => StockMovement::forCompany($this->company)->where('product_id', $p->id)->orderBy('id')->pluck('type')->map->value->all();

        // already trading (a real GRN of 10): selectable, entered as Additional stock in, ADDED to what is there
        $sold = $this->makeProduct($this->company, $this->owner, ['name' => 'ALREADY TRADING']);
        $this->stock($this->company, $this->owner, $sold, '10');
        $doc = $this->draft([$this->line($sold, '5', '100')]);
        $this->assertSame('additional', $doc->lines()->sole()->line_type);
        $this->service()->post($doc, $this->owner);
        $this->assertSame('15.0000', $this->onHandAt($sold));                                     // 10 + 5, nothing replaced
        $this->assertSame(['goods_receipt', 'stock_in'], $types($sold));
        $this->assertSame(0, OpeningStockRegistration::forCompany($this->company)->where('product_id', $sold->id)->count());

        // first opening at a location; a second document for the same product + location
        $fresh = $this->makeProduct($this->company, $this->owner, ['name' => 'FRESH']);
        $first = $this->draft([$this->line($fresh, '5', '100')]);
        $second = $this->draft([$this->line($fresh, '7', '100')]);                                 // both 'opening' while drafts
        $this->service()->post($first, $this->owner);
        // posting the second as OPENING is refused (it would be a second opening balance)...
        $this->assertThrowsMessage(fn () => $this->service()->post($second, $this->owner), 'it will be entered as Additional stock in');
        $this->assertSame('5.0000', $this->onHandAt($fresh));
        // another location of the same branch is still first-time stock while there is only opening activity
        $this->assertSame('opening', $this->draft([$this->line($fresh, '2', '100')], LocationType::MainStore)->lines()->sole()->line_type);
        // ...saving it again routes it to Additional stock in, which then adds 7
        $second = $this->service()->saveDraft($this->company, $second, ['branch_id' => $this->hq->id, 'location_id' => $this->loc()->id,
            'opening_date' => today()->toDateString()], [$this->line($fresh, '7', '100')], $this->owner);
        $this->assertSame('additional', $second->lines()->sole()->line_type);
        $this->service()->post($second, $this->owner);
        $this->assertSame('12.0000', $this->onHandAt($fresh));
        $this->assertSame(['opening_stock', 'stock_in'], $types($fresh));
        $this->assertSame(1, OpeningStockRegistration::forCompany($this->company)->where('product_id', $fresh->id)->count());

        // once anything else has happened in the branch (here: that stock-in, then a sale) it is additional stock everywhere there
        $device = $this->device($this->hq);
        $this->sell($this->company, $this->owner, $device, [['product_id' => $fresh->id, 'product_unit_id' => $this->baseUnitOf($fresh)->id, 'quantity' => '1']],
            [['payment_method_id' => $this->method($this->company)->id, 'amount' => '5000']]);
        $this->assertSame('additional', $this->draft([$this->line($fresh, '3', '100')], LocationType::MainStore)->lines()->sole()->line_type);

        // another BRANCH has its own initial stock
        $mbezi = app(BranchService::class)->create($this->company, ['name' => 'Mbezi']);
        $other = $this->service()->saveDraft($this->company, null, ['branch_id' => $mbezi->id,
            'location_id' => StockLocation::forCompany($this->company)->where('branch_id', $mbezi->id)->where('type', 'sales_floor')->value('id'),
            'opening_date' => today()->toDateString()], [$this->line($sold, '4', '100')], $this->owner);
        $this->assertSame('opening', $other->lines()->sole()->line_type);

        // the form accepts such products and labels them
        Livewire::test(OpeningStockForm::class)->call('pickProduct', $sold->id)->assertHasNoErrors('productSearch')
            ->assertSee('Additional stock in')->assertSee('Added on top of the existing stock');

        // the database guard still holds even if the eligibility check were bypassed
        $this->expectException(UniqueConstraintViolationException::class);
        OpeningStockRegistration::query()->insert(['company_id' => $this->company->id, 'branch_id' => $this->hq->id,
            'location_id' => $this->loc()->id, 'product_id' => $fresh->id, 'opening_stock_id' => $first->id, 'created_at' => now()]);
    }

    public function test_posted_documents_cannot_be_edited_and_drafts_can_be_cancelled(): void
    {
        $p = $this->makeProduct($this->company, $this->owner, ['name' => 'P']);
        $doc = $this->service()->post($this->draft([$this->line($p, '1', '10')]), $this->owner);
        $this->assertThrowsMessage(fn () => $this->service()->saveDraft($this->company, $doc, ['branch_id' => $this->hq->id, 'location_id' => $this->loc()->id,
            'opening_date' => today()->toDateString()], [$this->line($p, '99', '10')], $this->owner), 'already posted');
        $this->assertSame('1.0000', $doc->lines()->sole()->quantity);

        $q = $this->makeProduct($this->company, $this->owner, ['name' => 'Q']);
        $d = $this->draft([$this->line($q, '1', '10')]);
        $this->service()->cancel($d, $this->owner);
        $this->assertThrowsMessage(fn () => $this->service()->post($d, $this->owner), 'Cancelled');
        $this->assertSame(['created', 'cancelled'], $d->events()->pluck('event')->all());
    }

    // ---- isolation & permissions -------------------------------------------------------

    public function test_company_branch_and_permission_isolation(): void
    {
        $p = $this->makeProduct($this->company, $this->owner, ['name' => 'MINE']);

        // other company's location / product
        app(TenantContext::class)->clear();
        $other = $this->createCompany();
        $otherOwner = $this->createOwner($other);
        $this->actingAsTenant($otherOwner, $other);
        $foreign = $this->makeProduct($other, $otherOwner, ['name' => 'FOREIGN']);
        $foreignLocation = StockLocation::forCompany($other)->where('type', 'sales_floor')->first();
        $this->actingAsTenant($this->owner, $this->company);

        $this->assertThrowsMessage(fn () => $this->draft([$this->line($p, '1', '1')], header: ['location_id' => $foreignLocation->id]), 'Select an active stock location');
        $this->assertThrowsMessage(fn () => $this->draft([['product_id' => $foreign->id, 'product_unit_id' => $this->baseUnitOf($foreign)->id, 'quantity' => '1', 'unit_cost' => '1']]), 'Select a valid product');

        // branch the user cannot access
        $branch2 = app(BranchService::class)->create($this->company, ['name' => 'Mbezi']);
        $cashier = $this->addMember($this->company, SystemRole::Cashier, [$this->hq]);
        $manager = $this->addMember($this->company, SystemRole::BranchManager, [$this->hq]);
        $this->actingAsTenant($manager, $this->company);
        $this->expectsAuthorization(fn () => $this->service()->saveDraft($this->company, null, ['branch_id' => $branch2->id,
            'location_id' => StockLocation::forCompany($this->company)->where('branch_id', $branch2->id)->first()->id, 'opening_date' => today()->toDateString()], [$this->line($p, '1', '1')], $manager));

        // cashier: no access to creating opening stock at all
        $this->actingAs($cashier)->get(route('inventory.opening-stock.create'))->assertForbidden();
        $this->actingAs($cashier)->get(route('inventory.opening-stock.import'))->assertForbidden();
        $this->actingAs($cashier)->get(route('inventory.opening-stock.template'))->assertForbidden();
        $this->actingAsTenant($cashier, $this->company);
        $this->expectsAuthorization(fn () => $this->draft([$this->line($p, '1', '1')], actor: $cashier));
    }

    // ---- UI: scanner, search, draft, post -------------------------------------------------

    public function test_form_scans_searches_saves_and_posts(): void
    {
        $soap = $this->makeProduct($this->company, $this->owner, ['name' => 'DRS MEDICATED', 'barcode' => '6203013430060']);
        $loose = $this->makeProduct($this->company, $this->owner, ['name' => 'LOOSE BISCUITS']);         // no barcode

        $form = Livewire::test(OpeningStockForm::class)
            ->assertSet('location', $this->loc()->uuid)                                                  // sellable floor by default
            ->set('scan', '6203013430060')->call('scanBarcode')->assertSet('scan', '')->assertDispatched('scan-focus')
            ->set('scan', '6203013430060')->call('scanBarcode')                                          // repeat scan -> +1
            ->set('scan', '9999999999994')->call('scanBarcode')->assertHasErrors('scan')
            ->set('productSearch', 'loose')->assertSee('LOOSE BISCUITS')->call('pickProduct', $loose->id);

        $lines = $form->get('lines');
        $this->assertSame([['2', $soap->id], ['1', $loose->id]], array_map(fn ($l) => [$l['quantity'], $l['product_id']], $lines));

        $form->set('lines.0.quantity', '20')->set('lines.0.unit_cost', '500')->set('lines.1.unit_cost', '300')
            ->assertSee('10,300.00')                                                                     // live total
            ->call('saveDraft')->assertHasNoErrors()->assertRedirect();
        $doc = OpeningStock::forCompany($this->company)->sole();
        $this->assertSame(['draft', '10300.0000'], [$doc->status->value, $doc->total_value]);

        Livewire::test(OpeningStockForm::class, ['openingStock' => $doc])->assertSet('lines.0.quantity', '20')
            ->call('confirmPost')->assertSet('confirmingPost', true)
            ->call('post')->assertHasNoErrors()->assertRedirect(route('inventory.opening-stock.show', $doc));
        $this->assertSame('posted', $doc->fresh()->status->value);
        $this->assertSame(['20.0000', '1.0000'], [$this->onHandAt($soap), $this->onHandAt($loose)]);

        // a second (double-clicked / retried) post changes nothing
        Livewire::test(OpeningStockForm::class, ['openingStock' => $doc])->call('confirmPost')->assertHasErrors('status')->assertSet('confirmingPost', false);
        $this->assertSame(2, StockMovement::forCompany($this->company)->count());

        // posted view: read-only with audit trail; shows in stock movements with its own type
        $this->actingAs($this->owner)->get(route('inventory.opening-stock.show', $doc))->assertOk()
            ->assertSee($doc->reference)->assertSee('Posted by')->assertDontSee('data-post', false);
        $this->actingAs($this->owner)->get(route('inventory.movements'))->assertOk()->assertSee('Opening stock');
        $this->actingAs($this->owner)->get(route('inventory.stock'))->assertOk()->assertSee('DRS MEDICATED');
        $this->actingAs($this->owner)->get(route('inventory.opening-stock.index'))->assertOk()->assertSee($doc->reference)->assertSee('10,300.00');
    }

    public function test_store_keeper_prepares_and_manager_posts(): void
    {
        $p = $this->makeProduct($this->company, $this->owner, ['name' => 'RICE']);
        $keeper = $this->addMember($this->company, SystemRole::StoreKeeper, [$this->hq]);
        $this->actingAsTenant($keeper, $this->company);

        Livewire::test(OpeningStockForm::class)->call('pickProduct', $p->id)->set('lines.0.unit_cost', '2000')
            ->assertDontSee('data-post', false)->call('saveDraft')->assertHasNoErrors();
        $doc = OpeningStock::forCompany($this->company)->sole();
        Livewire::test(OpeningStockForm::class, ['openingStock' => $doc])->call('confirmPost')->assertForbidden();

        $manager = $this->addMember($this->company, SystemRole::BranchManager, [$this->hq]);
        $this->actingAsTenant($manager, $this->company);
        Livewire::test(OpeningStockForm::class, ['openingStock' => $doc])->call('confirmPost')->call('post')->assertHasNoErrors();
        $doc->refresh();
        $this->assertSame([$keeper->id, $manager->id], [$doc->created_by, $doc->posted_by]);
    }

    // ---- import ------------------------------------------------------------------------

    private function csv(string $content, string $name = 'stock.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    public function test_csv_import_preview_draft_and_post(): void
    {
        $soap = $this->makeProduct($this->company, $this->owner, ['name' => 'SOAP', 'barcode' => '6203013430060']);
        $rice = $this->makeProduct($this->company, $this->owner, ['name' => 'RICE']);
        $unused = $this->makeProduct($this->company, $this->owner, ['name' => 'NOT IN STOCK']);

        $file = $this->csv("\xEF\xBB\xBFSKU;Barcode;Product name;Qty;Cost price\n;6203013430060;Soap;20;500\n{$rice->sku};;Rice;\"1,000\";2200\n{$unused->sku};;Unused;;\n");
        $import = Livewire::test(OpeningStockImport::class)->set('file', $file)
            ->assertSet('mapping.barcode', '1')->assertSet('mapping.quantity', '3')->assertSet('mapping.unit_cost', '4')
            ->call('analyse');
        $result = $import->get('result');
        $this->assertSame([], $result['errors']);
        $this->assertSame([2, 1, '1020.0000', '2210000.0000'], [$result['totals']['rows'], $result['skipped'], $result['totals']['quantity'], $result['totals']['value']]);
        $this->assertSame(0, StockMovement::forCompany($this->company)->count());

        $import->call('createDraft')->assertHasNoErrors()->assertRedirect();
        $doc = OpeningStock::forCompany($this->company)->sole();
        $this->assertSame(['import', 'stock.csv', 'draft', 2], [$doc->source, $doc->import_filename, $doc->status->value, $doc->line_count]);
        $this->actingAs($this->owner)->get(route('inventory.opening-stock.show', $doc))->assertOk()->assertSee('Imported from')->assertSee('stock.csv');

        $this->actingAsTenant($this->owner, $this->company);
        $this->service()->post($doc, $this->owner);                                                  // same posting service
        $this->assertSame(['20.0000', '1000.0000'], [$this->onHandAt($soap), $this->onHandAt($rice)]);
        $this->noPurchasingRecords();
    }

    public function test_invalid_import_reports_every_row_and_saves_nothing(): void
    {
        $soap = $this->makeProduct($this->company, $this->owner, ['name' => 'SOAP', 'barcode' => '6203013430060']);
        $traded = $this->makeProduct($this->company, $this->owner, ['name' => 'TRADED']);
        $this->stock($this->company, $this->owner, $traded, '5');
        $movements = StockMovement::forCompany($this->company)->count();

        $file = $this->csv("sku,barcode,quantity,unit_cost,expires_on\n"
            .",6203013430060,10,500,\n"            // ok
            .",6203013430060,3,500,\n"             // duplicate of row 2
            ."NOPE,,1,1,\n"                         // unknown SKU
            ."{$traded->sku},,1,1,\n"              // already trading -> valid, as Additional stock in
            .",6203013430060,-1,500,\n"            // negative
            .",6203013430060,1,,\n"                // missing cost
            .",6203013430060,1,500,31/02/2026\n"); // invalid date
        $import = Livewire::test(OpeningStockImport::class)->set('file', $file)->call('analyse');
        $errors = $import->get('result')['errors'];
        $this->assertSame([3, 4, 6, 7, 8], array_keys($errors));
        $this->assertStringContainsString('listed twice', $errors[3][0]);
        $this->assertStringContainsString('NOPE', $errors[4][0]);
        $this->assertSame('additional', collect($import->get('result')['preview'])->firstWhere('row', 5)['type']);

        $import->call('createDraft')->assertHasErrors('file');
        $this->assertSame(0, OpeningStock::forCompany($this->company)->count());
        $this->assertSame($movements, StockMovement::forCompany($this->company)->count());
        $this->assertSame('0', $this->onHandAt($soap));
    }

    public function test_xlsx_import_with_packaging_barcode_unit_and_excel_dates(): void
    {
        $juice = $this->makeProduct($this->company, $this->owner, ['name' => 'JUICE', 'track_expiry' => true]);
        $carton = $this->packaging($juice, 'ctn', '24');
        app(BarcodeService::class)->add($juice, '16201234567890', BarcodeType::Packaging, $carton);
        $serial = (string) (int) (today()->addMonths(6)->timestamp / 86400 + 25569);   // Excel date serial

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="S" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>barcode</t></si><si><t>quantity</t></si><si><t>unit cost</t></si><si><t>expiry date</t></si><si><t>16201234567890</t></si></sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .'<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c><c r="D1" t="s"><v>3</v></c></row>'
            .'<row r="2"><c r="A2" t="s"><v>4</v></c><c r="B2"><v>3</v></c><c r="C2"><v>30000</v></c><c r="D2"><v>'.$serial.'</v></c></row>'
            .'</sheetData></worksheet>');
        $zip->close();

        $import = Livewire::test(OpeningStockImport::class)->set('file', UploadedFile::fake()->createWithContent('stock.xlsx', file_get_contents($path)))->call('analyse');
        $this->assertSame([], $import->get('result')['errors']);
        $import->call('createDraft')->assertHasNoErrors();
        $line = OpeningStock::forCompany($this->company)->sole()->lines()->sole();
        $this->assertSame([$carton->id, '72.0000', '1250.0000', today()->addMonths(6)->toDateString()],
            [$line->product_unit_id, $line->quantity_base, $line->unit_cost_base, $line->expires_on->toDateString()]);
        @unlink($path);
    }

    public function test_template_lists_products_and_neutralises_formulas(): void
    {
        $this->makeProduct($this->company, $this->owner, ['name' => '=HYPERLINK("http://evil")', 'barcode' => '6203013430060']);
        $csv = $this->actingAs($this->owner)->get(route('inventory.opening-stock.template'))->assertOk()->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBFsku,barcode,product_name,unit,quantity,unit_cost", $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString('6203013430060', $csv);
    }

    public function test_pages_are_responsive_and_translated(): void
    {
        $this->actingAs($this->owner)->get(route('inventory.opening-stock.create'))->assertOk()
            ->assertSee('New opening stock')->assertSee('data-barcode-scan', false)->assertSee('data-opening-summary', false)
            ->assertSee('grid-cols-2', false)->assertSee('env(safe-area-inset-bottom)', false)->assertDontSee('shift', false);
        $this->actingAs($this->owner)->get(route('company.dashboard'))->assertSee(route('inventory.opening-stock.index'), false);

        $this->owner->forceFill(['locale' => 'sw'])->save();
        $this->actingAs($this->owner->fresh())->get(route('inventory.opening-stock.index'))->assertOk()->assertSee('Stoo ya mwanzo');
        $this->actingAs($this->owner->fresh())->get(route('inventory.opening-stock.create'))->assertOk()->assertSee('Hifadhi rasimu')->assertSee('Stoo ya mwanzo mpya')->assertSee('Eneo la stoo');
    }

    // ---- helpers -------------------------------------------------------------------------

    private function assertThrowsMessage(callable $fn, string $needle): void
    {
        try {
            $fn();
            $this->fail("Expected an error containing \"{$needle}\".");
        } catch (ValidationException $e) {
            $this->assertStringContainsString($needle, implode(' ', collect($e->errors())->flatten()->all()));
        }
    }

    private function expectsAuthorization(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected an authorization failure.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }
}
