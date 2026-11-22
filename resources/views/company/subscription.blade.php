<x-layouts.company :title="__('Subscription')">
    <x-ui.page-header :title="__('Subscription')" :description="__('Your plan and its limits. Online payment will be available in a future release.')" />

    <div class="grid gap-4 lg:grid-cols-2">
        <div class="card">
            @if ($subscription)
                <p class="text-sm text-slate-500">{{ __('Current plan') }}</p>
                <p class="mt-1 text-2xl font-semibold">{{ __($subscription->plan->name) }}</p>
                <dl class="mt-4 space-y-3 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-500">{{ __('Status') }}</dt><dd class="font-medium">{{ __(ucfirst(str_replace('_', ' ', $subscription->status->value))) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">{{ __('Started') }}</dt><dd>{{ $subscription->starts_at->timezone($subscription->company->timezone)->format('d/m/Y') }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">{{ __('Ends') }}</dt><dd>{{ $subscription->ends_at?->timezone($subscription->company->timezone)->format('d/m/Y') ?? '—' }}</dd></div>
                </dl>
            @else
                <p class="font-semibold">{{ __('No active subscription') }}</p>
                <p class="mt-1 text-sm text-slate-500">{{ __('Existing data remains available, but new branches, staff and devices cannot be added.') }}</p>
            @endif
        </div>
        <div class="card">
            <p class="mb-4 text-sm font-semibold">{{ __('Usage') }}</p>
            @include('company._usage')
        </div>
    </div>
</x-layouts.company>
