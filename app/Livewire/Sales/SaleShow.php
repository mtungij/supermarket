<?php

namespace App\Livewire\Sales;

use App\Enums\PaymentMethodType;
use App\Enums\ReturnDisposition;
use App\Livewire\Concerns\InteractsWithTenant;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Services\Pos\SaleReturnService;
use App\Services\Pos\SalesQueries;
use App\Support\Decimal;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Sale detail, receipt reprint, and the controlled return / exchange workflow. */
#[Layout('components.layouts.tenant')]
class SaleShow extends Component
{
    use InteractsWithTenant;

    #[Locked]
    public string $saleUuid;

    #[Locked]
    public string $returnKey = '';

    public bool $showReturn = false;

    public string $reason = '';

    public string $refundMethod = '';

    public string $refundReference = '';

    /** @var array<int, array{quantity: string, disposition: string}> keyed by sale_item_id */
    public array $returnItems = [];

    public function mount(Sale $sale, SalesQueries $queries): void
    {
        abort_unless($queries->canSee(auth()->user(), $sale), 403);
        $this->saleUuid = $sale->uuid;
    }

    private function sale(): Sale
    {
        $sale = Sale::query()->where('uuid', $this->saleUuid)->firstOrFail();
        abort_unless(app(SalesQueries::class)->canSee(auth()->user(), $sale), 403);

        return $sale;
    }

    public function startReturn(SaleReturnService $returns): void
    {
        $this->authorize('sales.refund');
        $sale = $this->sale();
        $this->returnKey = (string) Str::uuid();
        $this->returnItems = $sale->items()->get()->mapWithKeys(fn ($i) => [$i->id => ['quantity' => '', 'disposition' => ReturnDisposition::Restock->value]])->all();
        $this->refundMethod = (string) PaymentMethod::query()->where('type', PaymentMethodType::Cash->value)->where('is_active', true)->value('uuid');
        $this->reset(['reason', 'refundReference']);
        $this->resetErrorBag();
        $this->showReturn = true;
    }

    public function returnAll(SaleReturnService $returns): void
    {
        foreach ($this->sale()->items()->get() as $item) {
            $this->returnItems[$item->id]['quantity'] = Decimal::trim(Decimal::div($returns->returnable($item), (string) $item->conversion_factor, Decimal::QTY));
        }
    }

    public function processReturn(SaleReturnService $returns): mixed
    {
        $this->authorize('sales.refund');
        $key = 'pos-return:'.auth()->id();
        if (RateLimiter::tooManyAttempts($key, 20)) {
            $this->addError('items', __('Too many return attempts. Please wait a moment.'));

            return null;
        }
        RateLimiter::hit($key, 60);

        $this->validate([
            'reason' => ['required', 'string', 'max:255'],
            'refundMethod' => ['required', 'uuid'],
            'refundReference' => ['nullable', 'string', 'max:100'],
            'returnItems.*.quantity' => ['nullable', 'string', 'max:20'],
            'returnItems.*.disposition' => ['required', Rule::enum(ReturnDisposition::class)],
        ]);

        $return = $returns->process(
            $this->sale(),
            $this->actor(),
            $this->returnKey,
            $this->reason,
            (int) PaymentMethod::query()->where('uuid', $this->refundMethod)->value('id'),
            collect($this->returnItems)->map(fn ($r, $id) => ['sale_item_id' => (int) $id] + $r)->values()->all(),
            $this->refundReference ?: null,
        );

        $this->showReturn = false;
        session()->flash('status', __('Return :ref recorded. Refund :amount.', ['ref' => $return->return_number, 'amount' => Decimal::format($return->refund_total)]));

        return SaleReturnService::isExchange($return)
            ? $this->redirectRoute('pos', ['exchange' => $return->uuid])
            : null;
    }

    public function render(SaleReturnService $returns): View
    {
        $sale = $this->sale()->load(['items.allocations.batch', 'payments', 'branch', 'cashier:id,name', 'device:id,name', 'customer', 'location:id,name']);
        $user = auth()->user();

        return view('livewire.sales.sale-show', [
            'sale' => $sale,
            'returnable' => $sale->items->mapWithKeys(fn ($i) => [$i->id => $returns->returnable($i)]),
            'returns' => SaleReturn::query()->where('sale_id', $sale->id)->with(['items.saleItem:id,product_name,unit_name,conversion_factor', 'items.location:id,name', 'processor:id,name', 'exchangeSale:id,uuid,sale_number'])->latest('id')->get(),
            'movements' => $user->can('inventory.view') ? StockMovement::query()->where('reference', $sale->sale_number)->with('location:id,name')->get() : collect(),
            'refundMethods' => PaymentMethod::query()->where('is_active', true)->orderBy('sort_order')->get(['uuid', 'name', 'type']),
            'dispositions' => ReturnDisposition::cases(),
            'tz' => $this->company()->timezone,
            'can' => ['refund' => $user->can('sales.refund'), 'cost' => $user->can('reports.financial')],
        ])->title($sale->sale_number);
    }
}
