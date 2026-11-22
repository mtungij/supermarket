<x-layouts.platform :title="__('Platform dashboard')">
    <x-ui.page-header :title="__('Platform overview')" :description="__('Live counts from the platform database.')" />

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card :label="__('Companies')" :value="number_format($stats['companies'])" icon="building" />
        <x-ui.stat-card :label="__('Active companies')" :value="number_format($stats['active_companies'])" icon="store" />
        <x-ui.stat-card :label="__('Active subscriptions')" :value="number_format($stats['usable_subscriptions'])" icon="credit-card" />
        <x-ui.stat-card :label="__('Users')" :value="number_format($stats['users'])" icon="users" />
    </div>

    <h3 class="mb-3 mt-10 text-sm font-semibold uppercase tracking-wider text-slate-500">{{ __('Coming in future phases') }}</h3>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <x-ui.module-card :label="__('Billing & payments')" icon="wallet" />
        <x-ui.module-card :label="__('Platform reports')" icon="chart" />
        <x-ui.module-card :label="__('Support & audit')" icon="shield" />
    </div>
</x-layouts.platform>
