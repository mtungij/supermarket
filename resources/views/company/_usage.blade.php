{{-- Plan usage rows. Expects $usage from SubscriptionLimiter::summary(). --}}
<dl class="space-y-3 text-sm">
    @foreach (['branches' => __('Branches'), 'staff' => __('Staff'), 'pos_devices' => __('POS devices')] as $key => $label)
        <div class="flex items-center justify-between gap-4">
            <dt class="text-slate-500 dark:text-slate-400">{{ $label }}</dt>
            <dd class="font-medium tabular-nums">{{ $usage[$key]['usage'] }} / {{ $usage[$key]['limit'] ?? __('Unlimited') }}</dd>
        </div>
    @endforeach
</dl>
