<x-layouts.company :title="__('Staff')">
    <x-ui.page-header :title="__('Staff')" :description="__('Members of this company and their roles.')" />
    @include('components.ui.flash')
    @can('staff.manage')
        <livewire:company.add-staff-member />
    @endcan

    <div class="card relative overflow-x-auto p-0">
        <table data-stack class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700">
            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-500 dark:bg-slate-800">
                <tr>
                    <th class="px-4 py-3">{{ __('Name') }}</th>
                    <th class="px-4 py-3">{{ __('Role') }}</th>
                    <th class="px-4 py-3">{{ __('Branches') }}</th>
                    <th class="px-4 py-3">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700/60">
                @foreach ($members as $member)
                    <tr>
                        <td class="px-4 py-3"><p class="font-medium">{{ $member->user->name }}</p><p class="text-xs text-slate-500">{{ $member->user->email }}</p></td>
                        <td class="px-4 py-3">{{ __($member->role->name) }}</td>
                        <td class="px-4 py-3">{{ $member->all_branches ? __('All branches') : ($member->branches->pluck('name')->join(', ') ?: '—') }}</td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $member->is_active ? 'bg-brand-50 text-brand-700 dark:bg-brand-900/40 dark:text-brand-100' : 'bg-slate-100 text-slate-500 dark:bg-slate-700' }}">
                                {{ $member->is_active ? __('Active') : __('Inactive') }}
                            </span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $members->links() }}</div>
</x-layouts.company>
