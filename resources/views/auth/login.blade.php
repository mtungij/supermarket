{{--
    Login = the app's entry point ("/" redirects guests here).

    Desktop (lg+): split screen. Left: supermarket photo + navy overlay with
    brand, message and feature highlights. Right: the form.
    Phones/tablets: the same photo fills the screen behind a compact card.

    The photo is a local, optimised WebP (+ JPEG fallback) - see
    public/images/login/CREDITS.md. If it fails to load, the navy gradient
    behind it is the fallback. Authentication itself is unchanged (Fortify:
    CSRF, throttling, remember me, password reset).
--}}
<x-layouts.base :title="__('Log in')">
    <div class="relative isolate flex min-h-dvh flex-col lg:grid lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)]" data-login-page>

        {{-- Photo panel (full-screen backdrop on phones, left half on desktop) --}}
        <aside class="absolute inset-0 -z-10 overflow-hidden bg-gradient-to-br from-[#0b1b34] via-[#0f2747] to-[#064e3b] lg:relative lg:inset-auto lg:z-0" data-login-hero>
            <picture>
                <source type="image/webp"
                        srcset="{{ asset('images/login/supermarket-768.webp') }} 768w, {{ asset('images/login/supermarket-1280.webp') }} 1280w, {{ asset('images/login/supermarket-1920.webp') }} 1920w"
                        sizes="(min-width: 1024px) 55vw, 100vw">
                <img src="{{ asset('images/login/supermarket-1280.jpg') }}" alt="" width="1280" height="985"
                     fetchpriority="high" decoding="async"
                     class="absolute inset-0 size-full object-cover object-[35%_center]"
                     onerror="this.style.display='none'">
            </picture>
            {{-- Navy overlay: keeps text readable over any part of the photo --}}
            <div class="absolute inset-0 bg-gradient-to-b from-[#071426]/75 via-[#0b1f3a]/55 to-[#071426]/85 lg:bg-gradient-to-br lg:from-[#071426]/90 lg:via-[#0b1f3a]/72 lg:to-[#064e3b]/60"></div>

            <div class="relative hidden h-full flex-col justify-between p-10 text-white lg:flex xl:p-14">
                <a href="{{ route('login') }}" class="flex w-fit items-center gap-3 text-xl font-semibold">
                    <span class="grid size-11 place-items-center rounded-xl bg-brand-500 text-white shadow-lg shadow-brand-900/40"><x-icon name="store" /></span>
                    {{ config('app.name') }}
                </a>

                <div class="max-w-xl">
                    <p class="mb-4 inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-brand-200 ring-1 ring-white/15">{{ __('Supermarket management software') }}</p>
                    <h2 class="text-4xl font-semibold leading-tight tracking-tight xl:text-5xl">{{ __('Run every till, shelf and branch from one place.') }}</h2>
                    <p class="mt-4 text-lg text-slate-200">{{ __('Fast barcode checkout, accurate stock and clear daily sales, built for supermarkets in Tanzania and beyond.') }}</p>

                    <ul class="mt-10 grid grid-cols-2 gap-4" data-login-features>
                        @foreach ([
                            ['cart', __('Point of sale'), __('Scan, sell and print receipts in seconds.')],
                            ['box', __('Inventory'), __('Stock by location, batches and expiry.')],
                            ['building', __('Multi-branch'), __('Every branch, one company account.')],
                            ['chart', __('Sales reports'), __('Daily totals, products sold and returns.')],
                        ] as [$icon, $title, $text])
                            <li class="rounded-2xl bg-white/[0.07] p-4 ring-1 ring-white/10 backdrop-blur-sm">
                                <span class="grid size-9 place-items-center rounded-lg bg-brand-500/20 text-brand-200"><x-icon :name="$icon" class="size-5" /></span>
                                <p class="mt-3 font-semibold">{{ $title }}</p>
                                <p class="mt-1 text-sm text-slate-300">{{ $text }}</p>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <p class="text-sm text-slate-400">© {{ now()->year }} {{ config('app.name') }}</p>
            </div>
        </aside>

        {{-- Form panel --}}
        <main class="relative flex min-h-dvh flex-1 flex-col lg:min-h-0 lg:bg-white dark:lg:bg-slate-950">
            <header class="flex items-center justify-between gap-3 px-4 py-3 sm:px-6 lg:justify-end lg:px-10 lg:py-6">
                <a href="{{ route('login') }}" class="flex min-w-0 items-center gap-2 font-semibold text-white lg:hidden">
                    <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-500"><x-icon name="store" /></span>
                    <span class="truncate">{{ config('app.name') }}</span>
                </a>
                <div class="flex shrink-0 items-center gap-1 rounded-2xl bg-white/90 p-1 shadow-sm ring-1 ring-black/5 backdrop-blur dark:bg-slate-900/90 dark:ring-white/10 lg:bg-transparent lg:shadow-none lg:ring-0 dark:lg:bg-transparent">
                    <x-app.locale-switcher />
                    <x-app.theme-toggle />
                </div>
            </header>

            <div class="flex flex-1 items-start justify-center px-4 pb-6 pt-2 sm:items-center sm:px-6 lg:px-10">
                <div class="w-full max-w-md">
                    <div class="rounded-3xl bg-white/95 p-6 shadow-2xl shadow-black/30 ring-1 ring-black/5 backdrop-blur sm:p-8 dark:bg-slate-900/95 dark:ring-white/10 lg:bg-transparent lg:p-0 lg:shadow-none lg:ring-0 lg:backdrop-blur-none dark:lg:bg-transparent" data-login-card>
                        <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ __('Welcome back') }}</h1>
                        <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">{{ __('Sign in to manage your supermarket.') }}</p>

                        @if (session('status'))
                            <div class="mt-4 rounded-xl bg-brand-50 px-4 py-3 text-sm text-brand-900 dark:bg-brand-900/30 dark:text-brand-100" role="status">{{ session('status') }}</div>
                        @endif

                        <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4 sm:space-y-5" x-data="{ busy: false }" x-on:submit="busy = true" x-on:pageshow.window="busy = false">
                            @csrf
                            <x-ui.input name="email" type="email" :label="__('Email')" autocomplete="username" inputmode="email" autocapitalize="off" spellcheck="false" required autofocus class="h-12" />

                            <div x-data="{ show: false }">
                                <label for="password" class="form-label">{{ __('Password') }}</label>
                                <div class="relative">
                                    <input id="password" name="password" type="password" :type="show ? 'text' : 'password'" autocomplete="current-password" required
                                           class="form-input h-12 pr-12" @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
                                    <button type="button" x-on:click="show = ! show" x-cloak
                                            class="absolute inset-y-0 right-0 grid w-12 place-items-center rounded-r-xl text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-white"
                                            :aria-label="show ? @js(__('Hide password')) : @js(__('Show password'))" :aria-pressed="show.toString()" aria-controls="password" data-password-toggle>
                                        <x-icon name="eye" class="size-5" x-show="! show" />
                                        <x-icon name="eye-off" class="size-5" x-show="show" />
                                    </button>
                                </div>
                                @error('password') <p id="password-error" class="form-error">{{ $message }}</p> @enderror
                            </div>

                            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                                <label class="flex min-h-11 items-center gap-3 text-sm">
                                    <input type="checkbox" name="remember" class="size-5 rounded border-slate-300 accent-brand-600 [color-scheme:light] dark:[color-scheme:dark]" @checked(old('remember'))>
                                    {{ __('Remember me') }}
                                </label>
                                <a href="{{ route('password.request') }}" class="inline-flex min-h-11 items-center text-sm font-semibold text-brand-700 hover:underline dark:text-brand-300">{{ __('Forgot your password?') }}</a>
                            </div>

                            <button type="submit" class="btn btn-primary h-12 w-full text-base" x-bind:disabled="busy" data-login-submit>
                                <span x-show="! busy">{{ __('Log in') }}</span>
                                <span x-show="busy" x-cloak>{{ __('Signing in…') }}</span>
                            </button>
                        </form>

                        <div class="mt-6 border-t border-slate-200 pt-5 text-center text-sm dark:border-slate-700">
                            <span class="text-slate-600 dark:text-slate-300">{{ __('New to the platform?') }}</span>
                            <a href="{{ route('register') }}" class="ml-1 inline-flex min-h-11 items-center font-semibold text-brand-700 hover:underline dark:text-brand-300" data-register-link>{{ __('Register your supermarket') }}</a>
                        </div>
                    </div>

                    {{-- Phones/tablets: short brand line under the card (desktop shows the full panel) --}}
                    <p class="mt-4 text-center text-xs text-slate-300 lg:hidden">{{ __('POS · Inventory · Multi-branch · Sales reports') }}</p>
                </div>
            </div>
        </main>
    </div>
</x-layouts.base>
