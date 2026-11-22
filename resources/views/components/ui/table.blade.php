{{--
    Responsive data table. From `sm` up it is a normal table (scrolling inside
    its card if ever wider than the screen). Below `sm` every row becomes a
    stacked card with "label: value" lines (labels copied from the header by
    resources/js/responsive-tables.js). `relative` keeps absolutely positioned
    content (e.g. sr-only header text) inside the scroll region.
    Slots: head (th cells), default (tbody rows).
--}}
@props(['stack' => true])
<div {{ $attributes->merge(['class' => 'card relative overflow-x-auto p-0']) }}>
    <table @if ($stack) data-stack @endif class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700">
        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-500 dark:bg-slate-800">
            <tr>{{ $head }}</tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-700/60">
            {{ $slot }}
        </tbody>
    </table>
</div>
