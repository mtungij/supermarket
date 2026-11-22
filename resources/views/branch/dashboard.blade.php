<x-layouts.branch :title="__('Branch dashboard')">
    @if ($branch)
        <x-ui.page-header :title="$branch->name" :description="__('Branch overview')" />

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <x-ui.stat-card :label="__('POS devices')" :value="$deviceCount" icon="device" />
            @can('pos.access')
                <div class="card flex flex-col justify-between">
                    <div>
                        <p class="text-sm font-medium text-slate-500">{{ __('Point of sale') }}</p>
                        <p class="mt-2 text-sm text-slate-500">{{ __('The POS screen arrives in a future release. Cashiers will sign in and sell directly — no shift to open or close.') }}</p>
                    </div>
                    <button type="button" class="btn btn-primary mt-4" disabled>{{ __('Open POS') }}</button>
                </div>
            @endcan
        </div>

        <h3 class="mb-3 mt-10 text-sm font-semibold uppercase tracking-wider text-slate-500">{{ __('Modules') }}</h3>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @can('sales.view') <x-ui.module-card :label="__('Sales')" icon="receipt" /> @endcan
            @can('products.view') <x-ui.module-card :label="__('Products')" icon="tag" /> @endcan
            @can('inventory.view') <x-ui.module-card :label="__('Inventory')" icon="box" /> @endcan
            @can('customers.view') <x-ui.module-card :label="__('Customers')" icon="users" /> @endcan
        </div>
    @else
        <div class="card text-center">
            <p class="font-semibold">{{ __('No branch assigned') }}</p>
            <p class="mt-1 text-sm text-slate-500">{{ __('Ask your manager to assign you to a branch.') }}</p>
        </div>
    @endif
</x-layouts.branch>
