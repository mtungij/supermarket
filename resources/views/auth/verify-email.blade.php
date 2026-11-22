<x-layouts.guest :title="__('Verify your email')">
    <div class="card p-6 sm:p-8">
        <h1 class="text-2xl font-semibold">{{ __('Verify your email') }}</h1>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ __('Please confirm your email address by clicking the link we just emailed to you.') }}</p>

        @if (session('status') === 'verification-link-sent')
            <div class="mt-4 rounded-xl bg-brand-50 px-4 py-3 text-sm text-brand-900 dark:bg-brand-900/30 dark:text-brand-100" role="status">{{ __('A new verification link has been sent to your email address.') }}</div>
        @endif

        <div class="mt-6 flex flex-col gap-3 sm:flex-row">
            <form method="POST" action="{{ route('verification.send') }}" class="flex-1">
                @csrf
                <button type="submit" class="btn btn-primary w-full">{{ __('Resend verification email') }}</button>
            </form>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="btn btn-secondary w-full">{{ __('Log out') }}</button>
            </form>
        </div>
    </div>
</x-layouts.guest>
