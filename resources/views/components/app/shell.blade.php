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
    {{--
        Off-canvas navigation below lg: hamburger opens it, the backdrop / Escape /
        any link closes it, the page behind does not scroll while it is open, and
        its links are removed from the tab order (inert) while it is closed.
    --}}
    <div x-data="{
            sidebarOpen: false,
            desktop: window.matchMedia('(min-width: 1024px)').matches,
            init() {
                const mq = window.matchMedia('(min-width: 1024px)');
                mq.addEventListener('change', (e) => { this.desktop = e.matches; if (e.matches) this.close(); });
                this.$watch('sidebarOpen', (open) => document.documentElement.classList.toggle('overflow-hidden', open && ! this.desktop));
            },
            open() { this.sidebarOpen = true; this.$nextTick(() => this.$refs.sidebarClose?.focus()); },
            close() { this.sidebarOpen = false; },
         }"
         x-on:keydown.escape.window="if (sidebarOpen) { close(); $refs.menuButton?.focus(); }"
         class="min-h-full">
        {{-- Mobile backdrop --}}
        <div x-cloak x-show="sidebarOpen" x-transition.opacity class="fixed inset-0 z-30 bg-slate-900/50 lg:hidden" @click="close()" aria-hidden="true"></div>

        {{-- Sidebar --}}
        <aside id="app-sidebar"
            class="fixed inset-y-0 left-0 z-40 flex w-72 max-w-[85vw] -translate-x-full flex-col border-r border-slate-200 bg-white transition-transform duration-200 lg:translate-x-0 dark:border-slate-800 dark:bg-slate-950"
            :class="{ 'translate-x-0': sidebarOpen }"
            x-effect="$el.inert = ! desktop && ! sidebarOpen"
            x-on:click="if ($event.target.closest('a[href]')) close()"
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
                <button type="button" x-ref="sidebarClose" class="grid size-11 place-items-center rounded-lg text-slate-500 hover:bg-slate-100 lg:hidden dark:hover:bg-slate-800" @click="close()" aria-label="{{ __('Close menu') }}">
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
        <div class="min-w-0 lg:pl-72">
            <x-app.topbar
                :title="$title"
                :company-options="$companyOptions"
                :branch-options="$branchOptions"
                :current-company="$currentCompany"
                :current-branch="$currentBranch" />

            <main id="main" class="mx-auto w-full max-w-screen-2xl px-3 py-5 sm:px-6 sm:py-6 lg:px-8">
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
