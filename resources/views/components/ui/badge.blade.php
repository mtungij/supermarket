@props(['color' => 'slate'])
@php
    $classes = [
        'slate' => 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-200',
        'green' => 'bg-brand-50 text-brand-700 dark:bg-brand-900/40 dark:text-brand-100',
        'amber' => 'bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-200',
        'red' => 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200',
        'blue' => 'bg-sky-50 text-sky-700 dark:bg-sky-900/30 dark:text-sky-200',
    ][$color] ?? '';
@endphp
<span {{ $attributes->merge(['class' => "inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {$classes}"]) }}>{{ $slot }}</span>
