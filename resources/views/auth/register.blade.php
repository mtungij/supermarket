<x-layouts.guest :title="__('Register your supermarket')" wide>
    <div class="card p-6 sm:p-8">
        <h1 class="text-2xl font-semibold">{{ __('Register your supermarket') }}</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Create your company, first branch and owner account. A free trial starts automatically.') }}</p>

        <form method="POST" action="{{ route('register') }}" class="mt-6 space-y-8">
            @csrf

            <fieldset class="space-y-5">
                <legend class="mb-4 text-sm font-semibold uppercase tracking-wider text-slate-500">{{ __('Company details') }}</legend>
                <x-ui.input name="company_name" :label="__('Company name')" required autofocus />
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.input name="company_email" type="email" :label="__('Company email')" required />
                    <x-ui.input name="company_phone" type="tel" :label="__('Company phone')" placeholder="+255 7XX XXX XXX" required />
                </div>
                <div>
                    <label for="business_address" class="form-label">{{ __('Business address') }}</label>
                    <textarea id="business_address" name="business_address" rows="2" class="form-input" required>{{ old('business_address') }}</textarea>
                    @error('business_address') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <x-ui.input name="branch_name" :label="__('First branch name')" :placeholder="__('e.g. Main Branch')" required />
            </fieldset>

            <fieldset class="space-y-5">
                <legend class="mb-4 text-sm font-semibold uppercase tracking-wider text-slate-500">{{ __('Owner account') }}</legend>
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.input name="owner_name" :label="__('Owner name')" autocomplete="name" required />
                    <x-ui.input name="owner_email" type="email" :label="__('Owner email')" autocomplete="username" required />
                </div>
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.input name="password" type="password" :label="__('Owner password')" autocomplete="new-password" required />
                    <x-ui.input name="password_confirmation" type="password" :label="__('Confirm password')" autocomplete="new-password" required />
                </div>
            </fieldset>

            <button type="submit" class="btn btn-primary w-full">{{ __('Create company') }}</button>
        </form>
    </div>

    <p class="mt-6 text-center text-sm text-slate-500 dark:text-slate-400">
        {{ __('Already registered?') }}
        <a href="{{ route('login') }}" class="font-semibold text-brand-700 hover:underline dark:text-brand-100">{{ __('Log in') }}</a>
    </p>
</x-layouts.guest>
