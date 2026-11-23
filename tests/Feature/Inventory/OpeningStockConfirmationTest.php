<?php

namespace Tests\Feature\Inventory;

use App\Enums\LocationType;
use App\Enums\SystemRole;
use App\Livewire\Inventory\OpeningStockForm;
use App\Models\Branch;
use App\Models\Company;
use App\Models\OpeningStock;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventory\OpeningStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesInventory;
use Tests\Concerns\CreatesPos;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * "Post opening stock" opens a server-validated confirmation dialog
 * (x-ui.confirm-dialog) instead of the browser's confirm(); posting still
 * goes through OpeningStockService::post().
 */
class OpeningStockConfirmationTest extends TestCase
{
    use CreatesInventory, CreatesPos, CreatesTenants, RefreshDatabase;

    protected bool $seed = true;

    private Company $company;

    private User $owner;

    private Branch $hq;

    private Product $drs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany();
        $this->owner = $this->createOwner($this->company);
        $this->actingAsTenant($this->owner, $this->company);
        $this->hq = $this->headOffice($this->company);
        $this->drs = $this->makeProduct($this->company, $this->owner, ['name' => 'DRS MEDICATED', 'barcode' => '6203013430060']);
    }

    private function form()
    {
        return Livewire::test(OpeningStockForm::class)
            ->set('scan', '6203013430060')->call('scanBarcode')
            ->set('lines.0.quantity', '20');
    }

    private function movements(): int
    {
        return StockMovement::forCompany($this->company)->count();
    }

    public function test_dialog_opens_with_server_computed_values_and_cancel_changes_nothing(): void
    {
        $form = $this->form()->set('lines.0.unit_cost', '500')
            ->call('confirmPost')->assertHasNoErrors()->assertSet('confirmingPost', true);

        $c = $form->get('confirmation');
        $this->assertSame([$this->hq->name, 'Sales Floor', true, 1, 1, '20.0000', '10000.0000', 0],
            [$c['branch'], $c['location'], $c['sellable'], $c['products'], $c['lines'], $c['quantity'], $c['value'], $c['zero_cost_lines']]);
        $form->assertSee('Post opening stock?')->assertSee('Confirm posting')->assertSee('10,000.00')
            ->assertSee('role="alertdialog"', false)->assertSee('aria-modal="true"', false)->assertSee('x-trap.inert.noscroll="open"', false);

        // nothing saved or posted by opening the dialog; Cancel/Escape (open=false) changes nothing
        $this->assertSame([0, 0], [OpeningStock::forCompany($this->company)->count(), $this->movements()]);
        $form->set('confirmingPost', false);
        $this->assertSame([0, 0], [OpeningStock::forCompany($this->company)->count(), $this->movements()]);
    }

    public function test_validation_errors_keep_the_dialog_closed(): void
    {
        // no lines
        Livewire::test(OpeningStockForm::class)->call('confirmPost')->assertHasErrors('lines')->assertSet('confirmingPost', false);
        // missing cost
        $this->form()->call('confirmPost')->assertHasErrors('lines.0.unit_cost')->assertSet('confirmingPost', false);
        // zero cost not confirmed / zero quantity / no location
        $this->form()->set('lines.0.unit_cost', '0')->call('confirmPost')->assertHasErrors('lines.0.unit_cost')->assertSet('confirmingPost', false);
        $this->form()->set('lines.0.quantity', '0')->set('lines.0.unit_cost', '5')->call('confirmPost')->assertHasErrors('lines.0.quantity');
        $this->form()->set('lines.0.unit_cost', '5')->set('location', '')->call('confirmPost')->assertHasErrors('location')->assertSet('confirmingPost', false);
        // zero cost confirmed but no reason
        $this->form()->set('lines.0.unit_cost', '0')->set('lines.0.zero_cost_confirmed', true)->call('confirmPost')
            ->assertHasErrors('zeroCostReason')->assertSet('confirmingPost', false);
        // eligibility: product already trading
        $this->actingAsTenant($this->owner, $this->company);
        $this->stock($this->company, $this->owner, $this->drs, '3');
        Livewire::test(OpeningStockForm::class)->set('scan', '6203013430060')->call('scanBarcode')->assertHasErrors('scan')->assertSet('lines', [])
            ->call('confirmPost')->assertHasErrors('lines')->assertSet('confirmingPost', false);
    }

    public function test_cost_error_clears_once_a_valid_cost_is_entered(): void
    {
        $form = $this->form()->call('confirmPost')->assertHasErrors('lines.0.unit_cost')
            ->assertSee('Enter the cost of DRS MEDICATED');

        $form->set('lines.0.unit_cost', '500')->assertHasNoErrors('lines.0.unit_cost')->assertDontSee('Enter the cost of DRS MEDICATED');
        $form->call('confirmPost')->assertHasNoErrors()->assertSet('confirmingPost', true);

        // removing a line clears line errors (indices shift)
        $p2 = $this->makeProduct($this->company, $this->owner, ['name' => 'SECOND']);
        $form->set('confirmingPost', false)->call('pickProduct', $p2->id)->call('confirmPost')->assertHasErrors('lines.1.unit_cost')
            ->call('removeLine', 0)->assertHasNoErrors('lines.1.unit_cost');
    }

    public function test_confirm_posts_once_and_redirects(): void
    {
        $form = $this->form()->set('lines.0.unit_cost', '500')->call('confirmPost');
        $form->call('post')->assertHasNoErrors()->assertSet('confirmingPost', false);
        $doc = OpeningStock::forCompany($this->company)->sole();
        $form->assertRedirect(route('inventory.opening-stock.show', $doc));
        $this->assertSame('posted', $doc->status->value);
        $this->assertSame(1, $this->movements());

        // a second Confirm click with the same confirmation is ignored
        $form->call('post');
        $this->assertSame(1, $this->movements());
        $this->assertSame(1, OpeningStock::forCompany($this->company)->count());

        // posting without the confirmation step does nothing
        $p2 = $this->makeProduct($this->company, $this->owner, ['name' => 'NO CONFIRM']);
        Livewire::test(OpeningStockForm::class)->call('pickProduct', $p2->id)->set('lines.0.unit_cost', '1')->call('post');
        $this->assertSame(1, OpeningStock::forCompany($this->company)->count());
    }

    public function test_failed_posting_keeps_the_dialog_open_with_the_reason_and_changes_no_stock(): void
    {
        $form = $this->form()->set('lines.0.unit_cost', '500')->call('confirmPost')->assertSet('confirmingPost', true);

        // meanwhile someone receives a GRN for the product: no longer opening stock
        $this->actingAsTenant($this->owner, $this->company);
        $this->stock($this->company, $this->owner, $this->drs, '5');
        $before = $this->movements();

        $form->call('post')->assertHasErrors()->assertSet('confirmingPost', true)
            ->assertSee('Nothing was posted.')->assertSee('already has stock activity');
        $this->assertSame($before, $this->movements());
        $this->assertSame(0, OpeningStock::forCompany($this->company)->count());   // server re-check failed before anything was saved

        // the same Confirm click cannot be replayed; a fresh check is required (and fails again with the reason)
        $form->call('post')->assertSet('confirmingPost', true);
        $this->assertSame($before, $this->movements());
        $form->call('confirmPost')->assertHasErrors('lines.0.product_id')->assertSet('confirmingPost', false);
    }

    public function test_permissions_on_the_confirmation_step(): void
    {
        $keeper = $this->addMember($this->company, SystemRole::StoreKeeper, [$this->hq]);
        $this->actingAsTenant($keeper, $this->company);
        $this->form()->set('lines.0.unit_cost', '500')->assertDontSee('data-post', false)->call('confirmPost')->assertForbidden();

        // manager without zero-cost permission is told before confirming
        $manager = $this->addMember($this->company, SystemRole::BranchManager, [$this->hq]);
        $this->actingAsTenant($manager, $this->company);
        $this->form()->set('lines.0.unit_cost', '0')->set('lines.0.zero_cost_confirmed', true)->set('zeroCostReason', 'Samples')
            ->call('confirmPost')->assertHasErrors('zeroCostReason')->assertSet('confirmingPost', false);
    }

    public function test_large_imported_draft_is_confirmed_from_stored_lines(): void
    {
        $doc = app(OpeningStockService::class)->saveDraft($this->company, null, ['branch_id' => $this->hq->id,
            'location_id' => $this->location($this->hq, LocationType::SalesFloor)->id, 'opening_date' => today()->toDateString()],
            [['product_id' => $this->drs->id, 'product_unit_id' => $this->baseUnitOf($this->drs)->id, 'quantity' => '7', 'unit_cost' => '100']], $this->owner);
        $doc->forceFill(['line_count' => OpeningStockForm::EDITABLE_LINES + 1])->save();   // render read-only, as for a big import

        $form = Livewire::test(OpeningStockForm::class, ['openingStock' => $doc])->call('confirmPost')->assertSet('confirmingPost', true);
        $this->assertSame(['7.0000', '700.0000'], [$form->get('confirmation')['quantity'], $form->get('confirmation')['value']]);
        $form->call('post')->assertHasNoErrors();
        $this->assertSame('posted', $doc->fresh()->status->value);
    }

    public function test_cancel_draft_uses_the_dialog_and_no_native_confirm_remains(): void
    {
        $doc = app(OpeningStockService::class)->saveDraft($this->company, null, ['branch_id' => $this->hq->id,
            'location_id' => $this->location($this->hq, LocationType::SalesFloor)->id, 'opening_date' => today()->toDateString()],
            [['product_id' => $this->drs->id, 'product_unit_id' => $this->baseUnitOf($this->drs)->id, 'quantity' => '1', 'unit_cost' => '1']], $this->owner);

        $view = file_get_contents(resource_path('views/livewire/inventory/opening-stock-form.blade.php'));
        $this->assertStringNotContainsString('wire:confirm', $view);
        $this->assertStringNotContainsString('window.confirm', $view);

        Livewire::test(OpeningStockForm::class, ['openingStock' => $doc])
            ->call('$set', 'confirmingCancel', true)->assertSee('Cancel this draft?')->assertSee('Keep draft')
            ->call('cancelDraft')->assertRedirect(route('inventory.opening-stock.index'));
        $this->assertSame('cancelled', $doc->fresh()->status->value);
        $this->assertSame(0, $this->movements());
    }

    public function test_dialog_markup_responsive_themes_and_kiswahili(): void
    {
        $html = $this->actingAs($this->owner)->get(route('inventory.opening-stock.create'))->assertOk()->getContent();
        foreach (['data-confirm-dialog="post"', 'items-center justify-center', 'max-h-[calc(100dvh-2rem)]', 'overflow-y-auto', 'dark:bg-slate-800',
            'x-on:keydown.escape.window', 'aria-haspopup="dialog"', 'data-dialog-cancel', 'data-dialog-confirm'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }

        $this->owner->forceFill(['locale' => 'sw'])->save();
        $this->actingAsTenant($this->owner->fresh(), $this->company);
        app()->setLocale('sw');
        $this->form()->set('lines.0.unit_cost', '500')->call('confirmPost')
            ->assertSee('Thibitisha stoo ya mwanzo?')->assertSee('Thibitisha sasa')->assertSee('Thamani ya stoo ya mwanzo')->assertSee('Ghairi');
    }
}
