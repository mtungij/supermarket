<div class="mx-auto max-w-2xl">
    <x-ui.page-header :title="__('Barcode lookup')" :description="__('Click in the box and scan. USB barcode scanners type the code and press Enter automatically.')" />

    <form wire:submit="lookup" class="card">
        <label for="lookup-code" class="form-label">{{ __('Barcode or SKU') }}</label>
        <input id="lookup-code" type="text" wire:model="code" autofocus autocomplete="off" class="form-input py-4 font-mono text-xl" x-init="$el.focus()" x-on:lookup-done.window="$el.focus()">
        @error('code') <p class="form-error">{{ $message }}</p> @enderror
        <button type="submit" class="btn btn-primary mt-4 w-full" wire:loading.attr="disabled">{{ __('Look up') }}</button>
    </form>

    @if ($notFound)
        <div class="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-900/30 dark:text-amber-100" role="status">{{ __('No product matches this code.') }}</div>
    @endif

    @if ($result)
        <div class="card mt-4" role="status">
            <p class="font-mono text-xs text-slate-500">{{ $result['code'] }}</p>
            <a href="{{ route('catalogue.products.show', $result['uuid']) }}" class="mt-1 block text-xl font-semibold text-brand-700 hover:underline dark:text-brand-100">{{ $result['product'] }}</a>
            <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                <div><dt class="text-slate-500">{{ __('SKU') }}</dt><dd>{{ $result['sku'] }}</dd></div>
                <div><dt class="text-slate-500">{{ __('Unit') }}</dt><dd>{{ __($result['unit']) }} (= {{ \App\Support\Decimal::trim($result['factor']) }} {{ __($result['base']) }})</dd></div>
                <div><dt class="text-slate-500">{{ __('Price') }}</dt><dd class="text-lg font-semibold">{{ \App\Support\Decimal::format($result['price']) }}</dd></div>
                <div><dt class="text-slate-500">{{ __('Status') }}</dt><dd><x-ui.status :status="$result['active'] ? 'active' : 'inactive'" /></dd></div>
            </dl>
        </div>
    @endif
</div>
