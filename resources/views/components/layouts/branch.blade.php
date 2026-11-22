{{-- Layout 3: Branch staff (focused on the current branch). --}}
@props(['title' => null])
<x-app.shell
    :title="$title"
    :navigation="$navigation"
    :area-label="$currentBranch?->name ?? $currentCompany?->name"
    :role-label="$currentRole"
    :company-options="$companyOptions"
    :branch-options="$branchOptions"
    :current-company="$currentCompany"
    :current-branch="$currentBranch"
    accent="bg-sky-600">
    {{ $slot }}
</x-app.shell>
