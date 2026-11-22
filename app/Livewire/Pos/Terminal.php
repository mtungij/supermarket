<?php

namespace App\Livewire\Pos;

use App\Enums\PaymentMethodType;
use App\Exceptions\BusinessRuleException;
use App\Livewire\Concerns\InteractsWithTenant;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\PosDevice;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockLocation;
use App\Services\Catalogue\UnitConversionService;
use App\Services\Pos\CartCalculator;
use App\Services\Pos\CheckoutService;
use App\Services\Pos\CustomerService;
use App\Services\Pos\PosCatalog;
use App\Services\Pos\PosRegisterResolver;
use App\Support\Decimal;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Online POS terminal. Cashiers open it directly (no shift screen).
 *
 * The cart lives on the server: the client can only change quantities,
 * units, (authorised) discounts, the customer and payments. Prices, tax,
 * totals and stock are always resolved server-side and recalculated
 * independently by CheckoutService.
 */
#[Layout('components.layouts.pos')]
#[Title('Point of sale')]
class Terminal extends Component
{
    use InteractsWithTenant;

    public const DEVICE_COOKIE = 'pos_device';

    #[Locked]
    public string $deviceUuid = '';

    /** Idempotency key of the current cart (new after every completed sale). */
    #[Locked]
    public string $checkoutKey = '';

    #[Locked]
    #[Url(as: 'exchange')]
    public string $exchangeUuid = '';

    /** @var array<string, array{product_id: int, product_unit_id: int, quantity: string, discount: string, barcode: ?string}> */
    public array $cart = [];

    public string $customerSearch = '';

    #[Locked]
    public ?string $customerUuid = null;

    public bool $showCustomerForm = false;

    public array $newCustomer = ['name' => '', 'phone' => ''];

    /** @var list<array{method: string, amount: string, reference: string}> */
    public array $payments = [];

    public bool $paying = false;

    public string $notice = '';

    #[Locked]
    public ?string $lastSaleUuid = null;

    public function mount(PosRegisterResolver $registers): void
    {
        $this->authorize('pos.access');
        $this->checkoutKey = (string) Str::uuid();

        $branch = $this->currentBranch();
        $remembered = session('pos.device.'.$branch->id) ?? request()->cookie(self::DEVICE_COOKIE);
        $this->deviceUuid = (string) ($registers->device($branch, $remembered)?->uuid ?? '');

        if ($this->exchangeUuid !== '') {
            $this->authorize('sales.refund');
            abort_if($this->exchangeReturn() === null, 404);
        }
    }

    // ---- Register ---------------------------------------------------------

    public function selectDevice(string $uuid, PosRegisterResolver $registers): void
    {
        $device = $registers->device($this->currentBranch(), $uuid) ?? abort(403);
        $this->deviceUuid = $device->uuid;
        session()->put('pos.device.'.$this->currentBranch()->id, $device->uuid);
        Cookie::queue(self::DEVICE_COOKIE, $device->uuid, 60 * 24 * 365);
        $this->dispatch('pos-focus');
    }

    public function changeDevice(): void
    {
        $this->deviceUuid = '';
        session()->forget('pos.device.'.$this->currentBranch()->id);
    }

    private function device(): ?PosDevice
    {
        return app(PosRegisterResolver::class)->device($this->currentBranch(), $this->deviceUuid);
    }

    private function sellingLocation(): ?StockLocation
    {
        return app(PosRegisterResolver::class)->sellingLocation($this->currentBranch(), $this->device());
    }

    // ---- Scanning & cart ---------------------------------------------------

    /** Called by the client scan queue with ONE code (scanner Enter or manual Enter). */
    public function scan(string $code, PosCatalog $catalog): void
    {
        $this->resetErrorBag('scan');
        $this->notice = '';
        $code = trim($code);

        if ($code === '' || mb_strlen($code) > 64) {
            return;
        }

        if (RateLimiter::tooManyAttempts('pos-scan:'.auth()->id(), 300)) {
            $this->addError('scan', __('Too many scans. Please wait a moment.'));

            return;
        }
        RateLimiter::hit('pos-scan:'.auth()->id(), 60);

        try {
            $item = $catalog->lookup($this->company(), $this->currentBranch(), $code);
            $this->addToCart($item->product, $item->unit, $item->barcode);
        } catch (ValidationException $e) {
            $this->addError('scan', collect($e->errors())->flatten()->first());
        }
    }

    /**
     * Live product search for products without (or with unreadable)
     * barcodes. Renderless: returns display rows to the Alpine search box
     * without re-rendering the POS. Results never decide what is sold;
     * addProduct() re-resolves the product, unit, price and stock.
     *
     * @return array{term: string, results: list<array<string, mixed>>}
     */
    #[Renderless]
    public function searchProducts(string $term, PosCatalog $catalog): array
    {
        $term = mb_substr(trim($term), 0, 64);

        if (RateLimiter::tooManyAttempts('pos-search:'.auth()->id(), 600)) {
            return ['term' => $term, 'results' => []];
        }
        RateLimiter::hit('pos-search:'.auth()->id(), 60);

        return ['term' => $term, 'results' => $catalog->searchSellable($this->company(), $this->currentBranch(), $this->sellingLocation(), $term)];
    }

    /**
     * Add a search result (by public UUID) with quantity 1, or +1 when it is
     * already in the cart. The product is resolved through the tenant scope,
     * so another company's product is simply "not found".
     */
    public function addProduct(string $uuid, PosCatalog $catalog): bool
    {
        $this->resetErrorBag('scan');
        $this->notice = '';
        $product = Product::query()->where('uuid', $uuid)->first();
        $added = false;

        try {
            if ($product === null) {
                throw BusinessRuleException::make('scan', __('Product not found.'));
            }
            $unit = $catalog->units($product)->first();
            if ($unit === null) {
                throw BusinessRuleException::make('scan', __(':product cannot be sold in this unit.', ['product' => $product->name]));
            }
            $item = $catalog->item($product, $unit, $this->currentBranch());
            $added = $this->addToCart($item->product, $item->unit, null);
        } catch (ValidationException $e) {
            $this->addError('scan', collect($e->errors())->flatten()->first());
        }

        // On success the search box hands focus back to the scanner itself;
        // on failure focus stays in the search so the cashier can pick again.
        return $added;
    }

    private function addToCart(Product $product, ProductUnit $unit, ?string $barcode): bool
    {
        $key = $product->id.'-'.$unit->id;
        $next = isset($this->cart[$key]) ? Decimal::trim(Decimal::add($this->validQty($this->cart[$key]['quantity']), '1', Decimal::QTY)) : '1';

        if (($warning = $this->stockProblem($product, $unit, $next)) !== null) {
            $this->addError('scan', $warning);

            return false;
        }

        if (isset($this->cart[$key])) {
            $this->cart[$key]['quantity'] = $next;
            // Move the line to the top so the cashier sees the scanned item.
            $this->cart = [$key => $this->cart[$key]] + $this->cart;
        } else {
            $this->cart = [$key => ['product_id' => $product->id, 'product_unit_id' => $unit->id, 'quantity' => '1', 'discount' => '0', 'barcode' => $barcode]] + $this->cart;
        }

        $this->notice = __(':product × :qty', ['product' => $product->name, 'qty' => $next]);
        $this->paying = false;

        return true;
    }

    public function increment(string $key): void
    {
        if (! isset($this->cart[$key])) {
            return;
        }
        $line = $this->cart[$key];
        $next = Decimal::trim(Decimal::add($this->validQty($line['quantity']), '1', Decimal::QTY));
        $product = Product::query()->findOrFail($line['product_id']);
        $unit = ProductUnit::query()->where('product_id', $product->id)->findOrFail($line['product_unit_id']);

        if (($warning = $this->stockProblem($product, $unit, $next)) !== null) {
            $this->addError("cart.{$key}.quantity", $warning);
        } else {
            $this->cart[$key]['quantity'] = $next;
        }
        $this->dispatch('pos-focus');
    }

    public function decrement(string $key): void
    {
        if (! isset($this->cart[$key])) {
            return;
        }
        $next = Decimal::sub($this->validQty($this->cart[$key]['quantity']), '1', Decimal::QTY);
        Decimal::isPositive($next) ? $this->cart[$key]['quantity'] = Decimal::trim($next) : $this->remove($key);
        $this->dispatch('pos-focus');
    }

    public function updatedCart(mixed $value, string $path): void
    {
        [$key, $field] = array_pad(explode('.', $path, 2), 2, null);

        if (! isset($this->cart[$key])) {
            return;
        }

        if ($field === 'discount' && ! auth()->user()->can('sales.discount')) {
            $this->cart[$key]['discount'] = '0';   // never trust a client-side discount
        }

        if ($field === 'product_unit_id') {
            $unit = ProductUnit::query()->where('product_id', $this->cart[$key]['product_id'])->where('can_sell', true)->where('is_active', true)->find((int) $value);
            if ($unit === null) {
                $this->cart[$key]['product_unit_id'] = (int) explode('-', $key)[1];

                return;
            }
            // Re-key so the same product+unit is never on two lines.
            $line = $this->cart[$key];
            unset($this->cart[$key]);
            $newKey = $line['product_id'].'-'.$unit->id;
            $this->cart[$newKey] = isset($this->cart[$newKey])
                ? array_merge($this->cart[$newKey], ['quantity' => Decimal::trim(Decimal::add($this->validQty($this->cart[$newKey]['quantity']), $this->validQty($line['quantity']), Decimal::QTY))])
                : $line;
        }
        $this->paying = false;
    }

    public function remove(string $key): void
    {
        unset($this->cart[$key]);
        $this->paying = false;
        $this->dispatch('pos-focus');
    }

    public function clearCart(): void
    {
        $this->reset(['cart', 'payments', 'paying', 'notice', 'customerUuid', 'customerSearch']);
        $this->resetErrorBag();
        $this->dispatch('pos-focus');
    }

    private function validQty(?string $qty): string
    {
        return Decimal::isValid((string) $qty) ? (string) $qty : '0';
    }

    /** Clear stock warning when the selling location cannot supply $quantity. */
    private function stockProblem(Product $product, ProductUnit $unit, string $quantity): ?string
    {
        $location = $this->sellingLocation();
        if ($location === null) {
            return __('No selling location is configured for this register.');
        }

        $available = app(PosCatalog::class)->available($location, $product->id);
        $other = collect($this->cart)->filter(fn ($l, $k) => $l['product_id'] === $product->id && $k !== $product->id.'-'.$unit->id)
            ->reduce(fn ($c, $l) => Decimal::add($c, Decimal::mul($this->validQty($l['quantity']), (string) ProductUnit::query()->whereKey($l['product_unit_id'])->value('factor'))), '0');
        $needed = Decimal::add($other, Decimal::mul($quantity, (string) $unit->factor));

        if (! Decimal::isPositive($available)) {
            return __(':product is out of stock at :location. Stock in other locations is not sold until it is transferred here.', ['product' => $product->name, 'location' => __($location->name)]);
        }

        return Decimal::cmp($needed, $available) > 0
            ? __('Only :available :unit of :product in :location.', ['available' => Decimal::trim($available), 'unit' => __($product->baseUnit->name), 'product' => $product->name, 'location' => __($location->name)])
            : null;
    }

    // ---- Customers ----------------------------------------------------------

    public function selectCustomer(?string $uuid): void
    {
        $this->authorize('customers.view');
        $this->customerUuid = $uuid ? (Customer::query()->where('uuid', $uuid)->where('is_active', true)->value('uuid') ?? abort(404)) : null;
        $this->customerSearch = '';
        $this->dispatch('pos-focus');
    }

    public function createCustomer(CustomerService $customers): void
    {
        $this->authorize('customers.manage');
        $this->validate(['newCustomer.name' => ['required', 'string', 'max:150'], 'newCustomer.phone' => ['nullable', 'string', 'max:30']], attributes: ['newCustomer.name' => __('name')]);

        try {
            $customer = $customers->save($this->company(), null, $this->newCustomer, $this->actor());
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ["newCustomer.{$k}" => $m])->all());
        }

        $this->customerUuid = $customer->uuid;
        $this->reset(['newCustomer', 'showCustomerForm']);
        $this->dispatch('pos-focus');
    }

    // ---- Payment & checkout -------------------------------------------------

    public function startPayment(): void
    {
        if ($this->cart === []) {
            return;
        }
        $total = $this->summary()['totals']['total'];
        $credit = $this->exchangeReturn();
        $this->payments = [];

        if ($credit !== null) {
            $exchange = PaymentMethod::query()->where('type', PaymentMethodType::ExchangeCredit->value)->where('is_active', true)->first();
            $use = Decimal::cmp((string) $credit->refund_total, $total) < 0 ? (string) $credit->refund_total : $total;
            $this->payments[] = ['method' => (string) $exchange?->uuid, 'amount' => Decimal::trim($use), 'reference' => $credit->return_number];
            $total = Decimal::sub($total, $use, Decimal::MONEY);
        }

        if (Decimal::isPositive($total)) {
            $cash = PaymentMethod::query()->where('type', PaymentMethodType::Cash->value)->where('is_active', true)->orderBy('sort_order')->first();
            $this->payments[] = ['method' => (string) $cash?->uuid, 'amount' => Decimal::trim($total), 'reference' => ''];
        }

        $this->paying = true;
    }

    public function addPayment(string $methodUuid): void
    {
        $remaining = Decimal::max(Decimal::sub($this->summary()['totals']['total'], $this->tendered()), '0');
        $this->payments[] = ['method' => $methodUuid, 'amount' => Decimal::isPositive($remaining) ? Decimal::trim($remaining) : '', 'reference' => ''];
        $this->paying = true;
    }

    public function removePayment(int $index): void
    {
        unset($this->payments[$index]);
        $this->payments = array_values($this->payments);
    }

    public function exactCash(int $index): void
    {
        $others = collect($this->payments)->except($index)->reduce(fn ($c, $p) => Decimal::isValid((string) $p['amount']) ? Decimal::add($c, (string) $p['amount']) : $c, '0');
        $this->payments[$index]['amount'] = Decimal::trim(Decimal::max(Decimal::sub($this->summary()['totals']['total'], $others), '0'));
    }

    public function checkout(CheckoutService $checkout): void
    {
        $key = 'pos-checkout:'.auth()->id();
        if (RateLimiter::tooManyAttempts($key, 30)) {
            $this->addError('payments', __('Too many checkout attempts. Please wait a moment.'));

            return;
        }
        RateLimiter::hit($key, 60);

        $methods = PaymentMethod::query()->get()->keyBy('uuid');
        $exchange = $this->exchangeReturn();

        try {
            $sale = $checkout->checkout(
                $this->company(),
                $this->currentBranch(),
                $this->device(),
                $this->actor(),
                $this->checkoutKey,
                array_values(array_map(fn ($l) => [
                    'product_id' => $l['product_id'], 'product_unit_id' => $l['product_unit_id'],
                    'quantity' => $l['quantity'], 'discount' => auth()->user()->can('sales.discount') ? $l['discount'] : '0',
                    'barcode' => $l['barcode'],
                ], $this->cart)),
                array_map(fn ($p) => [
                    'payment_method_id' => $methods->get($p['method'])?->id ?? 0,
                    'amount' => $p['amount'], 'reference' => $p['reference'],
                ], $this->payments),
                $this->customerUuid ? Customer::query()->where('uuid', $this->customerUuid)->value('id') : null,
                null,
                $exchange?->id,
            );
        } catch (ValidationException $e) {
            // Map "cart.N.x" errors from the service back to cart keys.
            $keys = array_keys($this->cart);
            $errors = collect($e->errors())->mapWithKeys(function ($messages, $field) use ($keys) {
                if (preg_match('/^cart\.(\d+)(\..+)?$/', $field, $m) && isset($keys[(int) $m[1]])) {
                    return ['cart.'.$keys[(int) $m[1]].($m[2] ?? '.quantity') => $messages];
                }

                return [$field => $messages];
            })->all();

            throw ValidationException::withMessages($errors);
        }

        $this->lastSaleUuid = $sale->uuid;
        $this->reset(['cart', 'payments', 'paying', 'notice', 'customerUuid', 'customerSearch', 'exchangeUuid']);
        $this->checkoutKey = (string) Str::uuid();
        $this->dispatch('sale-completed', url: route('sales.receipt', ['sale' => $sale->uuid, 'autoprint' => 1]));
        $this->dispatch('pos-focus');
    }

    public function newSale(): void
    {
        $this->lastSaleUuid = null;
        $this->dispatch('pos-focus');
    }

    private function tendered(): string
    {
        return collect($this->payments)->reduce(fn ($c, $p) => Decimal::isValid((string) $p['amount']) && Decimal::isPositive((string) $p['amount']) ? Decimal::add($c, (string) $p['amount']) : $c, '0');
    }

    private function exchangeReturn(): ?SaleReturn
    {
        return $this->exchangeUuid === '' ? null : SaleReturn::query()->where('uuid', $this->exchangeUuid)->first();
    }

    /** Display figures computed with the SAME rules as checkout (which recomputes them). */
    private function summary(): array
    {
        $catalog = app(PosCatalog::class);
        $calculator = app(CartCalculator::class);
        $conversions = app(UnitConversionService::class);
        $location = $this->sellingLocation();
        $branch = $this->currentBranch();
        $inclusive = (bool) data_get($this->company()->settings, 'prices_include_tax', true);
        $rows = [];

        $products = Product::query()->whereIn('id', array_column($this->cart, 'product_id'))->with('baseUnit:id,name')->get()->keyBy('id');
        $units = ProductUnit::query()->whereIn('product_id', $products->keys())->where('is_active', true)->where('can_sell', true)->with('unit:id,name')->get();

        foreach ($this->cart as $key => $line) {
            $product = $products->get($line['product_id']);
            $unit = $units->firstWhere('id', (int) $line['product_unit_id']);
            if ($product === null || $unit === null) {
                unset($this->cart[$key]);

                continue;
            }

            $quote = $catalog->item($product, $unit, $branch, $line['barcode'])->price;
            $rate = $product->tax_class->defaultRate();
            $error = null;
            $base = '0';
            $money = ['gross' => '0.00', 'discount' => '0.00', 'tax' => '0.00', 'net' => '0.00', 'total' => '0.00'];

            try {
                $base = $conversions->toBase($product, $unit, (string) $line['quantity'], "cart.{$key}.quantity");
                $money = $calculator->line(Decimal::normalize((string) $line['quantity'], Decimal::QTY), $quote->price, (string) ($line['discount'] ?: '0'), $rate, $inclusive);
            } catch (ValidationException $e) {
                $error = collect($e->errors())->flatten()->first();
            } catch (\InvalidArgumentException) {
                $error = __('Enter a valid discount.');
            }

            $available = $location ? $catalog->available($location, $product->id) : '0';
            $rows[$key] = [
                'product' => $product, 'unit' => $unit, 'units' => $units->where('product_id', $product->id),
                'price' => $quote->price, 'rate' => $rate, 'money' => $money, 'base' => $base, 'error' => $error,
                'available' => $available, 'short' => $error === null && Decimal::cmp($base, $available) > 0,
            ];
        }

        $totals = $calculator->totals(array_column($rows, 'money'));
        $tendered = $this->tendered();

        return [
            'rows' => $rows,
            'totals' => $totals,
            'inclusive' => $inclusive,
            'tendered' => Decimal::round($tendered, Decimal::MONEY),
            'balance' => Decimal::round(Decimal::sub($totals['total'], $tendered), Decimal::MONEY),
        ];
    }

    public function render(): View
    {
        $branch = $this->currentBranch();
        $registers = app(PosRegisterResolver::class);
        $device = $this->device();
        $summary = $this->summary();
        $user = auth()->user();
        $term = trim($this->customerSearch);

        return view('livewire.pos.terminal', [
            'branch' => $branch,
            'device' => $device,
            'needsDevice' => $device === null && $registers->requiresDevice($this->company()),
            'devices' => $registers->devices($branch),
            'location' => $this->sellingLocation(),
            'summary' => $summary,
            'methods' => PaymentMethod::query()->where('is_active', true)
                ->when($this->exchangeUuid === '', fn ($q) => $q->where('type', '!=', PaymentMethodType::ExchangeCredit->value))
                ->orderBy('sort_order')->get()->keyBy('uuid'),
            'customer' => $this->customerUuid ? Customer::query()->where('uuid', $this->customerUuid)->first() : null,
            'customerResults' => mb_strlen($term) >= 2 && $user->can('customers.view')
                ? Customer::query()->where('is_active', true)->where(fn ($q) => $q->where('name', 'like', '%'.addcslashes($term, '%_').'%')
                    ->orWhere('phone_key', 'like', '%'.(CustomerService::phoneKey($term) ?? '__none__').'%'))->orderBy('name')->limit(6)->get()
                : collect(),
            'lastSale' => $this->lastSaleUuid ? Sale::query()->where('uuid', $this->lastSaleUuid)->with('payments')->first() : null,
            'exchange' => $this->exchangeReturn(),
            'currency' => $this->company()->currency,
            'can' => [
                'discount' => $user->can('sales.discount'),
                'customers' => $user->can('customers.view'),
                'addCustomer' => $user->can('customers.manage'),
                'sales' => $user->can('sales.view') || $user->can('sales.create'),
            ],
        ]);
    }
}
