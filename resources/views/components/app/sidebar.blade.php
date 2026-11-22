@props(['navigation' => []])
<nav class="flex-1 overflow-y-auto px-3 py-4">
    @foreach ($navigation as $section)
        <p class="px-3 pb-2 pt-4 text-xs font-semibold uppercase tracking-wider text-slate-400 first:pt-0">{{ $section['section'] }}</p>
        <ul class="space-y-1">
            @foreach ($section['items'] as $item)
                <li>
                    @if ($item['url'] && ! $item['soon'])
                        <a href="{{ $item['url'] }}"
                           @class([
                               'flex min-h-11 items-center gap-3 rounded-xl px-3 text-sm font-medium transition',
                               'bg-brand-50 text-brand-700 dark:bg-brand-900/40 dark:text-brand-100' => $item['active'],
                               'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800' => ! $item['active'],
                           ])
                           @if ($item['active']) aria-current="page" @endif>
                            <x-icon :name="$item['icon']" />
                            {{ $item['label'] }}
                        </a>
                    @else
                        <span class="flex min-h-11 cursor-not-allowed items-center gap-3 rounded-xl px-3 text-sm font-medium text-slate-400 dark:text-slate-500" aria-disabled="true">
                            <x-icon :name="$item['icon']" />
                            <span class="flex-1">{{ $item['label'] }}</span>
                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-500 dark:bg-slate-800 dark:text-slate-400">{{ __('Soon') }}</span>
                        </span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endforeach
</nav>
