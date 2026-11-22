{{-- Layout 2: Company Admin / Owner (company-wide: company + branch selectors). --}}
@props(['title' => null])
<x-app.shell
    :title="$title"
    :navigation="$navigation"
    :area-label="$currentCompany?->name"
    :role-label="$currentRole"
    :company-options="$companyOptions"
    :branch-options="$branchOptions"
    :current-company="$currentCompany"
    :current-branch="$currentBranch"
    accent="bg-brand-600">
    {{ $slot }}
</x-app.shell>
