@props([
    'title' => null,
    'companyOptions' => collect(),
    'branchOptions' => collect(),
    'currentCompany' => null,
    'currentBranch' => null,
])
<header class="sticky top-0 z-20 border-b border-slate-200 bg-white/90 backdrop-blur dark:border-slate-800 dark:bg-slate-900/90">
    <div class="flex h-16 items-center gap-3 px-4 sm:px-6 lg:px-8">
        <button type="button" x-ref="menuButton" class="-ml-1 grid size-11 shrink-0 place-items-center rounded-lg text-slate-600 hover:bg-slate-100 lg:hidden dark:text-slate-300 dark:hover:bg-slate-800" @click="open()" aria-label="{{ __('Open menu') }}" aria-controls="app-sidebar" :aria-expanded="sidebarOpen.toString()">
            <x-icon name="menu" class="size-6" />
        </button>

        <h1 class="min-w-0 flex-1 truncate text-lg font-semibold">{{ $title }}</h1>

        {{-- Company selector: only for users with more than one active membership. --}}
        @if ($companyOptions->count() > 1)
            <form method="POST" action="{{ route('tenant.company.switch') }}" class="hidden md:block">
                @csrf
                <label for="company-switcher" class="sr-only">{{ __('Company') }}</label>
                <select id="company-switcher" name="company" onchange="this.form.submit()"
                        class="min-h-11 max-w-52 rounded-xl border border-slate-300 bg-white px-3 text-sm dark:border-slate-600 dark:bg-slate-800">
                    @foreach ($companyOptions as $option)
                        <option value="{{ $option['uuid'] }}" @selected($currentCompany?->uuid === $option['uuid'])>{{ $option['name'] }}</option>
                    @endforeach
                </select>
            </form>
        @endif

        {{-- Branch selector: only branches this membership may access. --}}
        @if ($branchOptions->count() > 1)
            <form method="POST" action="{{ route('tenant.branch.switch') }}" class="hidden sm:block">
                @csrf
                <label for="branch-switcher" class="sr-only">{{ __('Branch') }}</label>
                <select id="branch-switcher" name="branch" onchange="this.form.submit()"
                        class="min-h-11 max-w-48 rounded-xl border border-slate-300 bg-white px-3 text-sm dark:border-slate-600 dark:bg-slate-800">
                    @foreach ($branchOptions as $option)
                        <option value="{{ $option['uuid'] }}" @selected($currentBranch?->uuid === $option['uuid'])>{{ $option['name'] }}</option>
                    @endforeach
                </select>
            </form>
        @elseif ($currentBranch)
            <span class="hidden items-center gap-2 rounded-xl bg-slate-100 px-3 py-2 text-sm sm:inline-flex dark:bg-slate-800">
                <x-icon name="building" class="size-4" /> {{ $currentBranch->name }}
            </span>
        @endif

        <x-app.locale-switcher />
        <x-app.theme-toggle />

        <div x-data="{ open: false }" class="relative">
            <button type="button" @click="open = !open" @click.outside="open = false"
                    class="grid size-11 place-items-center rounded-full bg-slate-200 text-sm font-semibold text-slate-700 dark:bg-slate-700 dark:text-slate-100"
                    aria-haspopup="true" :aria-expanded="open" aria-label="{{ __('Account menu') }}">
                {{ str(auth()->user()->name)->substr(0, 1)->upper() }}
            </button>
            <div x-cloak x-show="open" x-transition
                 class="absolute right-0 mt-2 w-64 rounded-2xl border border-slate-200 bg-white p-2 shadow-lg dark:border-slate-700 dark:bg-slate-800">
                <div class="px-3 py-2">
                    <p class="truncate text-sm font-semibold">{{ auth()->user()->name }}</p>
                    <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ auth()->user()->email }}</p>
                </div>

                {{-- Mobile copies of the selectors --}}
                @if ($companyOptions->count() > 1)
                    <form method="POST" action="{{ route('tenant.company.switch') }}" class="px-3 py-2 md:hidden">
                        @csrf
                        <label for="company-switcher-m" class="form-label">{{ __('Company') }}</label>
                        <select id="company-switcher-m" name="company" onchange="this.form.submit()" class="form-input">
                            @foreach ($companyOptions as $option)
                                <option value="{{ $option['uuid'] }}" @selected($currentCompany?->uuid === $option['uuid'])>{{ $option['name'] }}</option>
                            @endforeach
                        </select>
                    </form>
                @endif
                @if ($branchOptions->count() > 1)
                    <form method="POST" action="{{ route('tenant.branch.switch') }}" class="px-3 py-2 sm:hidden">
                        @csrf
                        <label for="branch-switcher-m" class="form-label">{{ __('Branch') }}</label>
                        <select id="branch-switcher-m" name="branch" onchange="this.form.submit()" class="form-input">
                            @foreach ($branchOptions as $option)
                                <option value="{{ $option['uuid'] }}" @selected($currentBranch?->uuid === $option['uuid'])>{{ $option['name'] }}</option>
                            @endforeach
                        </select>
                    </form>
                @endif

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="flex min-h-11 w-full items-center gap-3 rounded-xl px-3 text-left text-sm font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/40">
                        <x-icon name="logout" /> {{ __('Log out') }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>
