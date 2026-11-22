{{-- Layout 1: Platform Super Admin (no tenant context, no company/branch selectors). --}}
@props(['title' => null])
<x-app.shell :title="$title" :navigation="$navigation" :area-label="__('Platform administration')" accent="bg-indigo-600">
    {{ $slot }}
</x-app.shell>
