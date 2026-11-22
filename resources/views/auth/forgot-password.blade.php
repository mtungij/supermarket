<x-layouts.guest :title="__('Forgot your password?')">
    <div class="card p-6 sm:p-8">
        <h1 class="text-2xl font-semibold">{{ __('Forgot your password?') }}</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Enter your email and we will send you a password reset link.') }}</p>

        @if (session('status'))
            <div class="mt-4 rounded-xl bg-brand-50 px-4 py-3 text-sm text-brand-900 dark:bg-brand-900/30 dark:text-brand-100" role="status">{{ session('status') }}</div>
        @endif

        <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-5">
            @csrf
            <x-ui.input name="email" type="email" :label="__('Email')" autocomplete="username" required autofocus />
            <button type="submit" class="btn btn-primary w-full">{{ __('Email password reset link') }}</button>
        </form>
    </div>
    <p class="mt-6 text-center text-sm"><a href="{{ route('login') }}" class="font-semibold text-brand-700 hover:underline dark:text-brand-100">{{ __('Back to log in') }}</a></p>
</x-layouts.guest>
