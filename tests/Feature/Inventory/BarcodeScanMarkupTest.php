<?php

namespace Tests\Feature\Inventory;

use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesInventory;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Regression for the stock-transfer scan bug: the scan input lived in a
 * <form> nested inside the transfer modal's <form>. Browsers drop nested
 * form tags, so the scanner's Enter submitted the OUTER form (save) and
 * scanBarcode never ran. Every scan input must be form-free and handle
 * Enter itself.
 */
class BarcodeScanMarkupTest extends TestCase
{
    use CreatesInventory, CreatesTenants, RefreshDatabase;

    protected bool $seed = true;

    public function test_scan_inputs_are_not_nested_in_forms_and_scan_on_enter(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);
        $this->makeProduct($company, $owner);

        foreach (['inventory.transfers', 'inventory.adjustments', 'purchasing.orders.create'] as $route) {
            $html = $this->actingAs($owner)->get(route($route))->assertOk()->getContent();

            $dom = new DOMDocument;
            @$dom->loadHTML($html);
            $xpath = new DOMXPath($dom);
            $inputs = $xpath->query('//input[@data-barcode-scan]');

            $this->assertSame(1, $inputs->length, "{$route}: one scan input");
            $this->assertSame(0, $xpath->query('ancestor::form', $inputs->item(0))->length, "{$route}: scan input must not be inside a <form>");
            $this->assertStringContainsString('scanBarcode', $inputs->item(0)->getAttribute('wire:keydown.enter.prevent'), "{$route}: Enter triggers the scan");
        }
    }
}
