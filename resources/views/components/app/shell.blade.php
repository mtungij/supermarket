@props([
    'title' => null,
    'navigation' => [],
    'areaLabel' => null,
    'roleLabel' => null,
    'companyOptions' => collect(),
    'branchOptions' => collect(),
    'currentCompany' => null,
    'currentBranch' => null,
    'accent' => 'bg-brand-600',
])
<x-layouts.base :title="$title">
    <div x-data="{ sidebarOpen: false }" class="min-h-full">
        {{-- Mobile overlay --}}
        <div x-cloak x-show="sidebarOpen" x-transition.opacity class="fixed inset-0 z-30 bg-slate-900/50 lg:hidden" @click="sidebarOpen = false"></div>

        {{-- Sidebar --}}
        <aside
            class="fixed inset-y-0 left-0 z-40 flex w-72 -translate-x-full flex-col border-r border-slate-200 bg-white transition-transform duration-200 lg:translate-x-0 dark:border-slate-800 dark:bg-slate-950"
            :class="{ 'translate-x-0': sidebarOpen }"
            aria-label="{{ __('Sidebar') }}">
            <div class="flex h-16 items-center justify-between gap-3 border-b border-slate-200 px-5 dark:border-slate-800">
                <a href="{{ route('dashboard') }}" class="flex min-w-0 items-center gap-3">
                    <span class="grid size-10 shrink-0 place-items-center rounded-xl text-white {{ $accent }}"><x-icon name="store" /></span>
                    <span class="min-w-0">
                        <span class="block truncate text-sm font-semibold">{{ config('app.name') }}</span>
                        @if ($areaLabel)
                            <span class="block truncate text-xs text-slate-500 dark:text-slate-400">{{ $areaLabel }}</span>
                        @endif
                    </span>
                </a>
                <button type="button" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 lg:hidden dark:hover:bg-slate-800" @click="sidebarOpen = false" aria-label="{{ __('Close menu') }}">
                    <x-icon name="x" />
                </button>
            </div>

            <x-app.sidebar :navigation="$navigation" />

            @if ($roleLabel)
                <div class="border-t border-slate-200 px-5 py-4 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
                    {{ __('Signed in as') }} <span class="font-medium text-slate-700 dark:text-slate-200">{{ __($roleLabel) }}</span>
                </div>
            @endif
        </aside>

        {{-- Main column --}}
        <div class="lg:pl-72">
            <x-app.topbar
                :title="$title"
                :company-options="$companyOptions"
                :branch-options="$branchOptions"
                :current-company="$currentCompany"
                :current-branch="$currentBranch" />

            <main class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                @if (session('status'))
                    <div class="mb-6 rounded-xl border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-900 dark:border-brand-900 dark:bg-brand-900/30 dark:text-brand-100" role="status">
                        {{ session('status') }}
                    </div>
                @endif

                {{ $slot }}
            </main>
        </div>
    </div>
</x-layouts.base>
