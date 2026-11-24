<x-layouts.company :title="__('Staff')">
    <x-ui.page-header :title="__('Staff')" :description="__('Members of this company and their roles.')" />
    @can('staff.manage')
        <livewire:company.add-staff-member />
    @endcan

    <livewire:company.staff-index />
</x-layouts.company>
