<x-layouts.guest :title="__('No active company')">
    <div class="card p-6 text-center sm:p-8">
        <span class="mx-auto grid size-14 place-items-center rounded-2xl bg-amber-50 text-amber-600 dark:bg-amber-900/30 dark:text-amber-300"><x-icon name="shield" class="size-7" /></span>
        <h1 class="mt-4 text-xl font-semibold">{{ __('No active company') }}</h1>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ __('Your account is not an active member of any company. Ask your company administrator to grant you access.') }}</p>
        <form method="POST" action="{{ route('logout') }}" class="mt-6">
            @csrf
            <button type="submit" class="btn btn-secondary w-full">{{ __('Log out') }}</button>
        </form>
    </div>
</x-layouts.guest>
