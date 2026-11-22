<x-layouts.guest :title="__('Log in')">
    <div class="card p-6 sm:p-8">
        <h1 class="text-2xl font-semibold">{{ __('Welcome back') }}</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Sign in to manage your supermarket.') }}</p>

        @if (session('status'))
            <div class="mt-4 rounded-xl bg-brand-50 px-4 py-3 text-sm text-brand-900 dark:bg-brand-900/30 dark:text-brand-100" role="status">{{ session('status') }}</div>
        @endif

        <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-5">
            @csrf
            <x-ui.input name="email" type="email" :label="__('Email')" autocomplete="username" required autofocus />
            <x-ui.input name="password" type="password" :label="__('Password')" autocomplete="current-password" required />

            <div class="flex items-center justify-between gap-4">
                <label class="flex min-h-11 items-center gap-3 text-sm">
                    <input type="checkbox" name="remember" class="size-5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                    {{ __('Remember me') }}
                </label>
                <a href="{{ route('password.request') }}" class="text-sm font-medium text-brand-700 hover:underline dark:text-brand-100">{{ __('Forgot your password?') }}</a>
            </div>

            <button type="submit" class="btn btn-primary w-full">{{ __('Log in') }}</button>
        </form>
    </div>

    <p class="mt-6 text-center text-sm text-slate-500 dark:text-slate-400">
        {{ __('New to the platform?') }}
        <a href="{{ route('register') }}" class="font-semibold text-brand-700 hover:underline dark:text-brand-100">{{ __('Register your supermarket') }}</a>
    </p>
</x-layouts.guest>
