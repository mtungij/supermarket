<?php

namespace App\Livewire\Catalogue;

use App\Livewire\Concerns\InteractsWithTenant;
use App\Services\Catalogue\BarcodeService;
use App\Services\Catalogue\PriceService;
use App\Support\Tenancy\TenantContext;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Scanner-friendly lookup. USB scanners act as keyboards: they type the
 * code into the focused input and press Enter, which submits the form.
 * This is the same lookup the future POS will use (no checkout here).
 */
#[Layout('components.layouts.tenant')]
#[Title('Barcode lookup')]
class BarcodeLookup extends Component
{
    use InteractsWithTenant;

    public string $code = '';

    public ?array $result = null;

    public bool $notFound = false;

    public function mount(): void
    {
        $this->authorize('products.view');
    }

    public function lookup(BarcodeService $barcodes, PriceService $prices): void
    {
        $this->validate(['code' => ['required', 'string', 'max:64']]);
        $match = $barcodes->lookup($this->company(), $this->code);
        $this->notFound = $match === null;
        $this->result = null;

        if ($match !== null) {
            $quote = $prices->resolve($match['product'], $match['product_unit'], app(TenantContext::class)->branch());
            $this->result = [
                'code' => BarcodeService::normalize($this->code),
                'product' => $match['product']->name,
                'uuid' => $match['product']->uuid,
                'sku' => $match['product']->sku,
                'unit' => $match['product_unit']->unit->name,
                'factor' => $match['product_unit']->factor,
                'base' => $match['product']->baseUnit->name,
                'price' => $quote->price,
                'active' => $match['product']->is_active,
            ];
        }

        $this->code = '';
    }

    public function render(): View
    {
        return view('livewire.catalogue.barcode-lookup');
    }
}
