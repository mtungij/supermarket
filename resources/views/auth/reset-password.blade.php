<x-layouts.guest :title="__('Reset password')">
    <div class="card p-6 sm:p-8">
        <h1 class="text-2xl font-semibold">{{ __('Reset password') }}</h1>

        <form method="POST" action="{{ route('password.update') }}" class="mt-6 space-y-5">
            @csrf
            <input type="hidden" name="token" value="{{ $request->route('token') }}">
            <div>
                <label for="email" class="form-label">{{ __('Email') }}</label>
                <input id="email" name="email" type="email" class="form-input" value="{{ old('email', $request->email) }}" autocomplete="username" required>
                @error('email') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <x-ui.input name="password" type="password" :label="__('New password')" autocomplete="new-password" required />
            <x-ui.input name="password_confirmation" type="password" :label="__('Confirm password')" autocomplete="new-password" required />
            <button type="submit" class="btn btn-primary w-full">{{ __('Reset password') }}</button>
        </form>
    </div>
</x-layouts.guest>
