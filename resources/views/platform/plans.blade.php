<x-layouts.platform :title="__('Subscription plans')">
    <x-ui.page-header :title="__('Subscription plans')" :description="__('Plan limits enforced for every company. Payment gateway integration is planned for a later phase.')" />

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($plans as $plan)
            <div class="card">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-lg font-semibold">{{ $plan->name }}</p>
                        <p class="text-xs text-slate-500">{{ $plan->code }}</p>
                    </div>
                    <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $plan->is_active ? 'bg-brand-50 text-brand-700 dark:bg-brand-900/40 dark:text-brand-100' : 'bg-slate-100 text-slate-500 dark:bg-slate-700' }}">
                        {{ $plan->is_active ? __('Active') : __('Inactive') }}
                    </span>
                </div>
                <p class="mt-4 text-2xl font-semibold">{{ $plan->currency }} {{ number_format((float) $plan->price) }}</p>
                <dl class="mt-4 grid grid-cols-2 gap-2 text-sm">
                    <dt class="text-slate-500">{{ __('Branches') }}</dt><dd class="text-right">{{ $plan->max_branches ?? __('Unlimited') }}</dd>
                    <dt class="text-slate-500">{{ __('Staff') }}</dt><dd class="text-right">{{ $plan->max_staff ?? __('Unlimited') }}</dd>
                    <dt class="text-slate-500">{{ __('POS devices') }}</dt><dd class="text-right">{{ $plan->max_pos_devices ?? __('Unlimited') }}</dd>
                    <dt class="text-slate-500">{{ __('Duration') }}</dt><dd class="text-right">{{ trans_choice(':count day|:count days', $plan->duration_days) }}</dd>
                    <dt class="text-slate-500">{{ __('Trial') }}</dt><dd class="text-right">{{ trans_choice(':count day|:count days', $plan->trial_days) }}</dd>
                </dl>
            </div>
        @empty
            <p class="text-slate-500">{{ __('No plans configured. Run the database seeders.') }}</p>
        @endforelse
    </div>
</x-layouts.platform>
