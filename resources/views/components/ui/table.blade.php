{{-- Responsive table card. Slots: head (th cells), default (tbody rows). --}}
<div {{ $attributes->merge(['class' => 'card overflow-x-auto p-0']) }}>
    <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700">
        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-500 dark:bg-slate-800">
            <tr>{{ $head }}</tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-700/60">
            {{ $slot }}
        </tbody>
    </table>
</div>
