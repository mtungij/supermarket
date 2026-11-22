{{-- Full-screen POS layout: no dashboard to navigate, no shift screen. --}}
@props(['title' => null])
<x-layouts.base :title="$title" class="min-h-full">
    <header class="sticky top-0 z-30 flex h-14 items-center gap-1.5 border-b border-slate-200 bg-white px-3 sm:px-4 dark:border-slate-800 dark:bg-slate-900">
        <a href="{{ route('dashboard') }}" class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-600 text-white" aria-label="{{ __('Dashboard') }}"><x-icon name="store" /></a>
        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-semibold">{{ app(\App\Support\Tenancy\TenantContext::class)->company()?->name }} · {{ app(\App\Support\Tenancy\TenantContext::class)->branch()?->name }}</p>
            <p class="truncate text-xs text-slate-500">{{ __('Cashier') }}: {{ auth()->user()->name }}</p>
        </div>
        {{-- Always visible (also on phones): previously the only sales link was hidden below "sm". --}}
        @if (auth()->user()->can('sales.view') || auth()->user()->can('sales.create'))
            <a href="{{ route('sales.index') }}" data-my-sales-link class="inline-flex min-h-11 min-w-11 shrink-0 items-center whitespace-nowrap justify-center gap-1.5 rounded-lg border border-slate-300 px-3 text-sm font-semibold hover:bg-slate-100 dark:border-slate-600 dark:hover:bg-slate-800"><x-icon name="receipt" class="size-4" /> <span class="max-[399px]:sr-only">{{ auth()->user()->can('sales.view') ? __('Sales') : __('My Sales') }}</span></a>
        @endif
        <x-app.locale-switcher />
        <x-app.theme-toggle />
        <form method="POST" action="{{ route('logout') }}" class="shrink-0">
            @csrf
            <button type="submit" class="grid size-11 place-items-center rounded-xl text-red-600 hover:bg-red-50 dark:hover:bg-red-950/40" aria-label="{{ __('Log out') }}"><x-icon name="logout" /></button>
        </form>
    </header>
    <main class="mx-auto w-full max-w-[1600px] p-3 sm:p-4">
        {{ $slot }}
    </main>
</x-layouts.base>
