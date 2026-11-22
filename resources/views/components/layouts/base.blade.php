@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light dark">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>

    {{-- Light theme by default; dark only when the user chose it. Runs before paint to avoid a flash. --}}
    <script>
        try { if (localStorage.getItem('theme') === 'dark') document.documentElement.classList.add('dark'); } catch (e) {}
    </script>

    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body {{ $attributes->merge(['class' => 'h-full bg-slate-50 font-sans text-slate-900 antialiased dark:bg-slate-900 dark:text-slate-100']) }}>
    {{ $slot }}

    @livewireScripts
</body>
</html>
