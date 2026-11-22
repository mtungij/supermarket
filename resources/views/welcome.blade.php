<x-layouts.guest wide>
    <div class="py-8 text-center sm:py-16">
        <span class="inline-flex items-center gap-2 rounded-full bg-brand-50 px-4 py-1.5 text-sm font-medium text-brand-700 dark:bg-brand-900/40 dark:text-brand-100">
            <x-icon name="store" class="size-4" /> {{ __('Supermarket management & point of sale') }}
        </span>
        <h1 class="mt-6 text-4xl font-bold tracking-tight sm:text-5xl">{{ __('Run every branch of your supermarket from one place') }}</h1>
        <p class="mx-auto mt-4 max-w-xl text-lg text-slate-600 dark:text-slate-300">{{ __('Branches, staff, POS devices, stock and sales for small, medium and large supermarkets.') }}</p>
        <div class="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
            <a href="{{ route('register') }}" class="btn btn-primary">{{ __('Register your supermarket') }}</a>
            <a href="{{ route('login') }}" class="btn btn-secondary">{{ __('Log in') }}</a>
        </div>
    </div>
</x-layouts.guest>
