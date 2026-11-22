@props(['label', 'value', 'hint' => null, 'icon' => 'chart'])
<div class="card">
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <p class="text-sm font-medium text-slate-500 dark:text-slate-400">{{ $label }}</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums">{{ $value }}</p>
            @if ($hint)
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $hint }}</p>
            @endif
        </div>
        <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-700 dark:bg-brand-900/40 dark:text-brand-100">
            <x-icon :name="$icon" />
        </span>
    </div>
</div>
