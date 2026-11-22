<x-layouts.company :title="__('Dashboard')">
    <x-ui.page-header :title="$company->name" :description="__('Company overview')" />

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card :label="__('Branches')" :value="$usage['branches']['usage']" :hint="__('Limit: :limit', ['limit' => $usage['branches']['limit'] ?? __('Unlimited')])" icon="building" />
        <x-ui.stat-card :label="__('Active staff')" :value="$usage['staff']['usage']" :hint="__('Limit: :limit', ['limit' => $usage['staff']['limit'] ?? __('Unlimited')])" icon="users" />
        <x-ui.stat-card :label="__('POS devices')" :value="$usage['pos_devices']['usage']" :hint="__('Limit: :limit', ['limit' => $usage['pos_devices']['limit'] ?? __('Unlimited')])" icon="device" />
        <x-ui.stat-card :label="__('Subscription')"
            :value="$subscription ? __($subscription->plan->name) : __('None')"
            :hint="$subscription ? __(':status · :days days left', ['status' => __(ucfirst(str_replace('_', ' ', $subscription->status->value))), 'days' => $subscription->daysRemaining()]) : __('No active subscription')"
            icon="credit-card" />
    </div>

    <h3 class="mb-3 mt-10 text-sm font-semibold uppercase tracking-wider text-slate-500">{{ __('Modules') }}</h3>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.module-card :label="__('Sales')" icon="receipt" />
        <x-ui.module-card :label="__('Products')" icon="tag" />
        <x-ui.module-card :label="__('Inventory')" icon="box" />
        <x-ui.module-card :label="__('Purchases')" icon="truck" />
        <x-ui.module-card :label="__('Suppliers')" icon="handshake" />
        <x-ui.module-card :label="__('Customers')" icon="users" />
        <x-ui.module-card :label="__('Expenses')" icon="wallet" />
        <x-ui.module-card :label="__('Reports')" icon="chart" />
    </div>
</x-layouts.company>
