<button type="button"
        x-data="{ dark: document.documentElement.classList.contains('dark') }"
        @click="dark = window.toggleTheme()"
        class="grid size-11 shrink-0 place-items-center rounded-xl text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800"
        :aria-label="dark ? @js(__('Switch to light mode')) : @js(__('Switch to dark mode'))">
    <x-icon name="moon" x-show="!dark" />
    <x-icon name="sun" x-show="dark" x-cloak />
</button>
