<x-layouts.platform :title="__('Companies')">
    <x-ui.page-header :title="__('Companies')" :description="__('All tenants registered on the platform.')" />

    <div class="card relative overflow-x-auto p-0">
        <table data-stack class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700">
            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-500 dark:bg-slate-800">
                <tr>
                    <th class="px-4 py-3">{{ __('Company') }}</th>
                    <th class="px-4 py-3">{{ __('Owner') }}</th>
                    <th class="px-4 py-3">{{ __('Branches') }}</th>
                    <th class="px-4 py-3">{{ __('Staff') }}</th>
                    <th class="px-4 py-3">{{ __('Status') }}</th>
                    <th class="px-4 py-3">{{ __('Registered') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700/60">
                @forelse ($companies as $company)
                    <tr>
                        <td class="px-4 py-3"><p class="font-medium">{{ $company->name }}</p><p class="text-xs text-slate-500">{{ $company->email }}</p></td>
                        <td class="px-4 py-3">{{ $company->owner?->name ?? '—' }}</td>
                        <td class="px-4 py-3 tabular-nums">{{ $company->branches_count }}</td>
                        <td class="px-4 py-3 tabular-nums">{{ $company->memberships_count }}</td>
                        <td class="px-4 py-3"><span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium dark:bg-slate-700">{{ __(ucfirst($company->status->value)) }}</span></td>
                        <td class="px-4 py-3 text-slate-500">{{ $company->created_at->timezone(config('saas.default_timezone'))->format('d/m/Y') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-slate-500">{{ __('No companies have registered yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $companies->links() }}</div>
</x-layouts.platform>
