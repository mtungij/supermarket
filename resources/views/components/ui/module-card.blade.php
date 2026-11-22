{{-- Placeholder for a module delivered in a later phase. Never shows invented figures. --}}
@props(['label', 'icon' => 'box', 'description' => null])
<div class="card border-dashed">
    <div class="flex items-center gap-3">
        <span class="grid size-11 place-items-center rounded-xl bg-slate-100 text-slate-500 dark:bg-slate-700/60 dark:text-slate-300">
            <x-icon :name="$icon" />
        </span>
        <div class="min-w-0">
            <p class="font-semibold">{{ $label }}</p>
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ $description ?? __('Available in a future release') }}</p>
        </div>
    </div>
    <p class="mt-4 text-sm text-slate-400 dark:text-slate-500">{{ __('No data yet') }}</p>
</div>
