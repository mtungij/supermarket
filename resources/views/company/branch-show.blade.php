<x-layouts.company :title="$branch->name">
    <x-ui.page-header :title="$branch->name" :description="$branch->code" />
    <div class="grid gap-4 md:grid-cols-3">
        <x-ui.stat-card :label="__('POS devices')" :value="$branch->pos_devices_count" icon="device" />
        <x-ui.stat-card :label="__('Status')" :value="$branch->is_active ? __('Active') : __('Inactive')" icon="building" />
        <x-ui.stat-card :label="__('Type')" :value="$branch->is_head_office ? __('Head office') : __('Branch')" icon="store" />
    </div>
    <div class="card mt-4 text-sm">
        <dl class="grid gap-3 sm:grid-cols-2">
            <div><dt class="text-slate-500">{{ __('Phone') }}</dt><dd>{{ $branch->phone ?? '—' }}</dd></div>
            <div><dt class="text-slate-500">{{ __('Address') }}</dt><dd>{{ $branch->address ?? '—' }}</dd></div>
        </dl>
    </div>
</x-layouts.company>






