@props(['title' => null, 'wide' => false])
<x-layouts.base :title="$title">
    <div class="flex min-h-full flex-col">
        <header class="flex items-center justify-between px-4 py-4 sm:px-8">
            <a href="{{ route('login') }}" class="flex items-center gap-2 text-lg font-semibold">
                <span class="grid size-10 place-items-center rounded-xl bg-brand-600 text-white"><x-icon name="store" /></span>
                {{ config('app.name') }}
            </a>
            <div class="flex items-center gap-2">
                <x-app.locale-switcher />
                <x-app.theme-toggle />
            </div>
        </header>

        <main class="flex flex-1 items-start justify-center px-4 pb-12 pt-4 sm:items-center sm:px-6">
            <div @class(['w-full', 'max-w-3xl' => $wide, 'max-w-md' => ! $wide])>
                {{ $slot }}
            </div>
        </main>
    </div>
</x-layouts.base>
