@use('App\Support\Decimal')
<div x-data="{ typing: false }"
     x-on:sale-completed.window="window.printReceipt($event.detail.url)"
     x-on:focusin="typing = window.innerWidth < 1024 && $event.target.matches('input:not([data-barcode-scan]), select, textarea')"
     x-on:focusout="typing = false">
    @if ($needsDevice)
        {{-- Register selection (once per browser; remembered). No shift is opened. --}}
        <div class="mx-auto max-w-xl card mt-6 space-y-4">
            <h1 class="text-xl font-semibold">{{ __('Select your register') }}</h1>
            <p class="text-sm text-slate-500">{{ __('Sales are recorded against a registered POS device of :branch.', ['branch' => $branch->name]) }}</p>
            @forelse ($devices as $d)
                <button type="button" wire:click="selectDevice('{{ $d->uuid }}')" class="btn btn-secondary w-full justify-between"><span>{{ $d->name }}</span><span class="text-xs text-slate-500">{{ $d->receipt_paper_width }} mm</span></button>
            @empty
                <div class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-900/30 dark:text-amber-100">{{ __('No POS device is registered for this branch. Ask a manager to register one under Company → POS devices.') }}</div>
            @endforelse
        </div>
    @elseif ($lastSale)
        {{-- Sale completed --}}
        <div class="mx-auto mt-6 max-w-lg card space-y-4 text-center" role="status">
            <span class="mx-auto grid size-16 place-items-center rounded-full bg-brand-50 text-brand-700 dark:bg-brand-900/40 dark:text-brand-100"><x-icon name="receipt" class="size-8" /></span>
            <h1 class="text-2xl font-semibold">{{ __('Sale :ref completed', ['ref' => $lastSale->sale_number]) }}</h1>
            <p class="text-3xl font-bold tabular-nums">{{ $currency }} {{ Decimal::format($lastSale->total) }}</p>
            @if (Decimal::isPositive((string) $lastSale->change_total))
                <p class="rounded-xl bg-amber-50 px-4 py-3 text-xl font-semibold text-amber-900 dark:bg-amber-900/30 dark:text-amber-100">{{ __('Change') }}: {{ Decimal::format($lastSale->change_total) }}</p>
            @endif
            <div class="grid gap-3 sm:grid-cols-2">
                <button type="button" x-on:click="window.printReceipt(@js(route('sales.receipt', ['sale' => $lastSale->uuid, 'autoprint' => 1])))" class="btn btn-secondary">{{ __('Print receipt') }}</button>
                <button type="button" wire:click="newSale" class="btn btn-primary" autofocus>{{ __('New sale') }}</button>
            </div>
        </div>
    @else
        <div class="grid gap-4 pb-28 lg:grid-cols-[minmax(0,1fr)_380px] lg:pb-0 2xl:grid-cols-[minmax(0,1fr)_440px]">
            {{-- LEFT: scan, search, cart --}}
            <section class="min-w-0 space-y-3">
                @if ($exchange)
                    <div class="rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900 dark:border-sky-900 dark:bg-sky-900/30 dark:text-sky-100">{{ __('Exchange for return :ref · credit :amount', ['ref' => $exchange->return_number, 'amount' => Decimal::format($exchange->refund_total)]) }}</div>
                @endif
                <div class="card sticky top-14 z-10 space-y-3 p-3 shadow-md sm:p-4 lg:static lg:shadow-sm" x-data="posScanner()">
                    <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500">
                        <span>{{ __('Register') }}: <span class="font-medium text-slate-700 dark:text-slate-200">{{ $device?->name ?? __('None') }}</span> · {{ __('Selling from') }}: <span class="font-medium text-slate-700 dark:text-slate-200">{{ $location ? __($location->name) : '—' }}</span></span>
                        @if ($device) <button type="button" wire:click="changeDevice" class="underline">{{ __('Change register') }}</button> @endif
                    </div>
                    <label for="pos-scan" class="sr-only">{{ __('Scan barcode') }}</label>
                    <div class="flex gap-2">
                        <input id="pos-scan" x-ref="scan" data-barcode-scan type="text" wire:ignore x-on:keydown.enter.prevent="submit()"
                               enterkeyhint="enter" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" autofocus
                               class="form-input min-w-0 flex-1 py-4 font-mono text-xl" placeholder="{{ __('Scan barcode or type code + Enter') }}">
                        <button type="button" x-on:click="submit()" class="btn btn-secondary shrink-0">{{ __('Add') }}</button>
                    </div>
                    <div aria-live="polite" class="min-h-6 text-sm">
                        @error('scan') <p class="font-medium text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        @if ($notice !== '' && ! $errors->has('scan')) <p class="text-brand-700 dark:text-brand-100">{{ $notice }}</p> @endif
                        <p x-show="busy" x-cloak class="text-xs text-slate-500">{{ __('Looking up…') }}</p>
                    </div>
                    <div class="relative">
                        <label for="pos-search" class="sr-only">{{ __('Search products') }}</label>
                        <input id="pos-search" type="search" wire:model.live.debounce.300ms="search" class="form-input" placeholder="{{ __('Search by name, SKU or barcode') }}" autocomplete="off">
                        @if ($results->isNotEmpty())
                            <ul class="absolute z-20 mt-1 max-h-80 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-lg dark:border-slate-700 dark:bg-slate-800">
                                @foreach ($results as $r)
                                    <li><button type="button" wire:click="addProduct({{ $r->id }})" class="flex min-h-12 w-full items-center justify-between gap-3 px-4 py-3 text-left hover:bg-slate-50 dark:hover:bg-slate-700"><span class="font-medium">{{ $r->name }}</span><span class="text-xs text-slate-500">{{ $r->sku }} · {{ Decimal::format($r->selling_price) }}</span></button></li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>

                <div class="space-y-2">
                    @forelse ($summary['rows'] as $key => $row)
                        <div wire:key="line-{{ $key }}" @class(['card p-3 sm:p-4', 'border-red-400 dark:border-red-700' => $row['short'] || $row['error']])>
                            <div class="flex items-start gap-3">
                                <div class="min-w-0 flex-1">
                                    <p class="break-words text-base font-semibold">{{ $row['product']->name }}</p>
                                    <p class="text-xs text-slate-500">{{ $row['product']->sku }}@if ($cart[$key]['barcode']) · {{ $cart[$key]['barcode'] }}@endif · {{ __('Available') }}: {{ Decimal::trim($row['available']) }} {{ __($row['product']->baseUnit->name) }}</p>
                                </div>
                                <p class="shrink-0 text-right text-lg font-bold tabular-nums">{{ Decimal::format($row['money']['total']) }}</p>
                            </div>
                            <div class="mt-3 flex flex-wrap items-center gap-2">
                                <div class="flex items-center rounded-xl border border-slate-300 dark:border-slate-600">
                                    <button type="button" wire:click="decrement('{{ $key }}')" class="grid size-12 place-items-center text-xl font-bold" aria-label="{{ __('Decrease') }}">−</button>
                                    <input type="text" inputmode="decimal" wire:model.live.debounce.500ms="cart.{{ $key }}.quantity" class="h-12 w-20 border-x border-slate-300 bg-transparent text-center text-lg font-semibold dark:border-slate-600" aria-label="{{ __('Quantity') }}">
                                    <button type="button" wire:click="increment('{{ $key }}')" class="grid size-12 place-items-center text-xl font-bold" aria-label="{{ __('Increase') }}">+</button>
                                </div>
                                <select wire:model.live="cart.{{ $key }}.product_unit_id" class="form-input w-auto min-w-28" aria-label="{{ __('Unit') }}">
                                    @foreach ($row['units'] as $u) <option value="{{ $u->id }}">{{ __($u->unit->name) }}{{ $u->is_base ? '' : ' ×'.Decimal::trim($u->factor) }}</option> @endforeach
                                </select>
                                <span class="text-sm text-slate-500">× {{ Decimal::format($row['price']) }}</span>
                                @if ($can['discount'])
                                    <label class="flex items-center gap-1 text-sm text-slate-500">{{ __('Discount') }}<input type="text" inputmode="decimal" wire:model.live.debounce.500ms="cart.{{ $key }}.discount" class="h-11 w-24 rounded-lg border border-slate-300 bg-transparent px-2 dark:border-slate-600"></label>
                                @elseif (Decimal::isPositive($row['money']['discount']))
                                    <span class="text-sm">−{{ Decimal::format($row['money']['discount']) }}</span>
                                @endif
                                <span class="text-xs text-slate-500">{{ __('VAT') }} {{ Decimal::trim($row['rate']) }}% · {{ Decimal::format($row['money']['tax']) }}</span>
                                <button type="button" wire:click="remove('{{ $key }}')" class="ml-auto min-h-11 rounded-lg px-3 text-sm font-medium text-red-600 hover:bg-red-50 dark:hover:bg-red-950/40">{{ __('Remove') }}</button>
                            </div>
                            @if ($row['error']) <p class="form-error">{{ $row['error'] }}</p> @endif
                            @error("cart.$key.quantity") <p class="form-error">{{ $message }}</p> @enderror
                            @error("cart.$key.discount") <p class="form-error">{{ $message }}</p> @enderror
                            @if ($row['short']) <p class="form-error">{{ __('Only :qty :unit available at :location.', ['qty' => Decimal::trim($row['available']), 'unit' => __($row['product']->baseUnit->name), 'location' => __($location?->name ?? '')]) }}</p> @endif
                        </div>
                    @empty
                        <div class="card py-12 text-center text-slate-500">{{ __('Scan a product to start a sale.') }}</div>
                    @endforelse
                </div>
            </section>

            {{-- RIGHT: customer, totals, payment --}}
            <aside class="space-y-3 lg:sticky lg:top-[4.5rem] lg:max-h-[calc(100vh-5.5rem)] lg:self-start lg:overflow-y-auto" id="pos-checkout">
                @if ($can['customers'])
                    <div class="card space-y-2 p-3 sm:p-4">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-semibold">{{ __('Customer') }}</p>
                            @if ($customer) <button type="button" wire:click="selectCustomer(null)" class="text-xs underline">{{ __('Walk-in') }}</button> @endif
                        </div>
                        @if ($customer)
                            <p class="font-medium">{{ $customer->name }} <span class="text-xs text-slate-500">{{ $customer->phone }}</span></p>
                        @else
                            <p class="text-sm text-slate-500">{{ __('Walk-in / Cash customer') }}</p>
                            <div class="relative">
                                <input type="search" wire:model.live.debounce.300ms="customerSearch" class="form-input" placeholder="{{ __('Find customer by name or phone') }}" aria-label="{{ __('Find customer') }}">
                                @if ($customerResults->isNotEmpty())
                                    <ul class="absolute z-20 mt-1 w-full rounded-xl border border-slate-200 bg-white shadow-lg dark:border-slate-700 dark:bg-slate-800">
                                        @foreach ($customerResults as $c) <li><button type="button" wire:click="selectCustomer('{{ $c->uuid }}')" class="flex min-h-11 w-full justify-between px-4 py-2 text-left text-sm hover:bg-slate-50 dark:hover:bg-slate-700"><span>{{ $c->name }}</span><span class="text-slate-500">{{ $c->phone }}</span></button></li> @endforeach
                                    </ul>
                                @endif
                            </div>
                            @if ($can['addCustomer'])
                                @if ($showCustomerForm)
                                    <div class="grid gap-2">
                                        <input type="text" wire:model="newCustomer.name" class="form-input" placeholder="{{ __('Name') }}" aria-label="{{ __('Name') }}">@error('newCustomer.name') <p class="form-error">{{ $message }}</p> @enderror
                                        <input type="tel" wire:model="newCustomer.phone" class="form-input" placeholder="{{ __('Phone') }}" aria-label="{{ __('Phone') }}">@error('newCustomer.phone') <p class="form-error">{{ $message }}</p> @enderror
                                        <button type="button" wire:click="createCustomer" class="btn btn-secondary">{{ __('Save customer') }}</button>
                                    </div>
                                @else
                                    <button type="button" wire:click="$set('showCustomerForm', true)" class="text-sm font-medium text-brand-700 dark:text-brand-100">+ {{ __('New customer') }}</button>
                                @endif
                            @endif
                        @endif
                    </div>
                @endif

                <div class="card space-y-2 p-3 sm:p-4">
                    <dl class="space-y-1 text-sm">
                        <div class="flex justify-between"><dt class="text-slate-500">{{ __('Subtotal') }}</dt><dd class="tabular-nums">{{ Decimal::format($summary['totals']['subtotal']) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">{{ __('Discount') }}</dt><dd class="tabular-nums">−{{ Decimal::format($summary['totals']['discount']) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">{{ $summary['inclusive'] ? __('VAT (included)') : __('VAT') }}</dt><dd class="tabular-nums">{{ Decimal::format($summary['totals']['tax']) }}</dd></div>
                    </dl>
                    <div class="flex items-end justify-between border-t border-slate-200 pt-2 dark:border-slate-700">
                        <span class="font-semibold">{{ __('Total') }}</span>
                        <span class="text-3xl font-bold tabular-nums">{{ $currency }} {{ Decimal::format($summary['totals']['total']) }}</span>
                    </div>
                    <div class="grid grid-cols-2 gap-2 pt-1">
                        <button type="button" wire:click="clearCart" wire:confirm="{{ __('Clear the whole cart?') }}" class="btn btn-secondary" @disabled($cart === [])>{{ __('Clear cart') }}</button>
                        <button type="button" wire:click="startPayment" class="btn btn-primary" @disabled($cart === [])>{{ __('Pay') }}</button>
                    </div>
                </div>

                @if ($paying && $cart !== [])
                    <div class="card scroll-mt-20 space-y-3 p-3 sm:p-4" id="pos-payment">
                        <p class="text-sm font-semibold">{{ __('Payment') }}</p>
                        @foreach ($payments as $i => $p)
                            @php($m = $methods->get($p['method']))
                            <div wire:key="pay-{{ $i }}" class="space-y-2 rounded-xl border border-slate-200 p-2 dark:border-slate-700">
                                <div class="flex items-center gap-2">
                                    <select wire:model.live="payments.{{ $i }}.method" class="form-input" aria-label="{{ __('Payment method') }}">@foreach ($methods as $uuid => $method) <option value="{{ $uuid }}">{{ __($method->name) }}</option> @endforeach</select>
                                    <button type="button" wire:click="removePayment({{ $i }})" class="grid size-11 shrink-0 place-items-center rounded-lg text-red-600" aria-label="{{ __('Remove') }}"><x-icon name="x" /></button>
                                </div>
                                <div class="flex gap-2">
                                    <input type="text" inputmode="decimal" wire:model.live.debounce.400ms="payments.{{ $i }}.amount" class="form-input text-lg font-semibold" placeholder="{{ __('Amount received') }}" aria-label="{{ __('Amount received') }}">
                                    @if ($m?->type->value === 'cash') <button type="button" wire:click="exactCash({{ $i }})" class="btn btn-secondary shrink-0">{{ __('Exact') }}</button> @endif
                                </div>
                                @if ($m && ($m->requires_reference || $m->type->value !== 'cash'))
                                    <input type="text" wire:model="payments.{{ $i }}.reference" class="form-input" placeholder="{{ $m->requires_reference ? __('Reference (required)') : __('Reference (optional)') }}" aria-label="{{ __('Reference') }}">
                                @endif
                                @error("payments.$i.amount") <p class="form-error">{{ $message }}</p> @enderror
                                @error("payments.$i.reference") <p class="form-error">{{ $message }}</p> @enderror
                                @error("payments.$i.payment_method_id") <p class="form-error">{{ $message }}</p> @enderror
                            </div>
                        @endforeach
                        <div class="flex flex-wrap gap-2">
                            @foreach ($methods as $uuid => $method)
                                <button type="button" wire:click="addPayment('{{ $uuid }}')" class="rounded-lg border border-slate-300 px-3 py-2 text-sm dark:border-slate-600">+ {{ __($method->name) }}</button>
                            @endforeach
                        </div>
                        <dl class="space-y-1 text-sm">
                            <div class="flex justify-between"><dt>{{ __('Amount due') }}</dt><dd class="tabular-nums">{{ Decimal::format($summary['totals']['total']) }}</dd></div>
                            <div class="flex justify-between"><dt>{{ __('Amount received') }}</dt><dd class="tabular-nums">{{ Decimal::format($summary['tendered']) }}</dd></div>
                            @if (Decimal::isNegative($summary['balance']))
                                <div class="flex justify-between text-lg font-bold text-amber-700 dark:text-amber-300"><dt>{{ __('Change') }}</dt><dd class="tabular-nums">{{ Decimal::format(Decimal::abs($summary['balance'])) }}</dd></div>
                            @elseif (Decimal::isPositive($summary['balance']))
                                <div class="flex justify-between font-semibold text-red-600"><dt>{{ __('Still to pay') }}</dt><dd class="tabular-nums">{{ Decimal::format($summary['balance']) }}</dd></div>
                            @endif
                        </dl>
                        @foreach (['payments', 'cart', 'device', 'customer', 'subscription'] as $f) @error($f) <p class="form-error">{{ $message }}</p> @enderror @endforeach
                        <p class="text-xs text-slate-500">{{ __('Mobile money and card payments are confirmed manually by you; no automatic provider check is made.') }}</p>
                        <button type="button" wire:click="checkout" wire:loading.attr="disabled" wire:target="checkout" class="btn btn-primary w-full py-4 text-lg">
                            <span wire:loading.remove wire:target="checkout">{{ __('Complete sale') }}</span><span wire:loading wire:target="checkout">{{ __('Processing…') }}</span>
                        </button>
                    </div>
                @endif
            </aside>
        </div>

        {{-- Phones/tablets: total + the next checkout step always reachable.
             Hidden while a text field is focused so the on-screen keyboard
             cannot push it over the field being edited. --}}
        <div x-show="! typing" class="fixed inset-x-0 bottom-0 z-20 flex items-center gap-2 border-t border-slate-200 bg-white p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] shadow-[0_-4px_12px_rgba(0,0,0,0.06)] lg:hidden dark:border-slate-700 dark:bg-slate-900" data-pos-mobile-bar>
            <div class="min-w-0 flex-1">
                <p class="truncate text-xs text-slate-500">{{ __('Total') }} ({{ $currency }}) · {{ trans_choice(':count item|:count items', count($cart)) }}</p>
                <p class="truncate text-lg font-bold tabular-nums min-[400px]:text-xl" aria-label="{{ __('Total') }}: {{ $currency }} {{ Decimal::format($summary['totals']['total']) }}">{{ Decimal::format($summary['totals']['total']) }}</p>
            </div>
            @if ($paying && $cart !== [])
                <button type="button" x-on:click="document.getElementById('pos-payment')?.scrollIntoView({ behavior: 'smooth', block: 'start' })" class="btn btn-secondary shrink-0 px-3" aria-label="{{ __('Payment') }}"><x-icon name="wallet" /></button>
                <button type="button" wire:click="checkout" wire:loading.attr="disabled" wire:target="checkout" class="btn btn-primary shrink-0 px-4">
                    <span wire:loading.remove wire:target="checkout">{{ __('Complete sale') }}</span><span wire:loading wire:target="checkout">{{ __('Processing…') }}</span>
                </button>
            @else
                <button type="button" wire:click="startPayment" x-on:click="$nextTick(() => setTimeout(() => document.getElementById('pos-payment')?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 350))" class="btn btn-primary shrink-0 px-8" @disabled($cart === [])>{{ __('Pay') }}</button>
            @endif
        </div>
    @endif
</div>
