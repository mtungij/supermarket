{{-- Phase 2 module pages: company-wide members get the company layout, branch staff the branch layout. --}}
@props(['title' => null])
@if ($companyWide ?? false)
    <x-layouts.company :title="$title">{{ $slot }}</x-layouts.company>
@else
    <x-layouts.branch :title="$title">{{ $slot }}</x-layouts.branch>
@endif
