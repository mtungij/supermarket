# Tenant security

Goal: **no user can ever read or change another company's data**, and branch
staff cannot reach branches they are not assigned to.

## Principles

1. **The tenant comes from memberships, never from input.** The current
   company is resolved from the authenticated user's *active* memberships in
   *active* companies. A `company_id` in a form, query string, Livewire
   payload or session is never trusted. The session only stores a
   *preference*, which is re-validated on every request.
2. **Fail closed.** The `CompanyScope` global scope adds `WHERE 1 = 0` when no
   tenant context exists. If tenant middleware is missing somewhere, the
   result is empty, not every company's rows.
3. **Defense in depth.** Each layer protects on its own:

| Layer | Mechanism |
|---|---|
| Resolution | `TenantResolver` accepts only active memberships in active companies, and re-validates on every request and every Livewire update |
| Query | `CompanyScope` on every `BelongsToCompany` model (fail-closed) |
| Write | `BelongsToCompany` forces `company_id` from context, rejects a mismatched `company_id`, and makes `company_id` immutable |
| Mass assignment | `company_id`, `branch_id`, `platform_role_id`, `is_active` and membership fields are not fillable |
| Routing | Tenant context resolves before route model binding, so a foreign UUID returns 404 |
| Authorization | Gates per permission. Policies check permission **and** company **and** branch access |
| Services | Services verify branch, role and company ownership before writing |
| Database | Composite FKs `(branch_id, company_id)` reject cross-company references |
| Identifiers | Public URLs and forms use UUIDs, not sequential IDs |

## Company switching

`POST /tenant/company` with `company=<uuid>`. The resolver looks the UUID up
among the user's active memberships (constant-time comparison). Unknown,
inactive or suspended targets return **403**. On success the CSRF token is
regenerated and the branch selection is reset.

## Branch access

- `all_branches` memberships may use every active branch of the company.
- Other memberships may use only branches in `branch_company_user`.
- `POST /tenant/branch` accepts only branches from that list and returns 403
  otherwise. A stale or forged branch id in the session is replaced on the
  next request.

## Platform administration

- Platform admins hold a **platform-scope** role on `users.platform_role_id`.
- They are created only by `php artisan platform:create-admin`. Public
  registration ignores any role or admin fields.
- They have **no tenant permissions** and no implicit access to tenant areas.
  There is no `Gate::before` bypass. Platform pages that list tenants use
  explicit `withoutTenancy()` queries.
- Platform admin accounts cannot be added to a company or used as a company
  owner.

## Rules for future code

- Make every tenant-owned model use `BelongsToCompany`. Never add
  `company_id` to `$fillable`.
- Never call `withoutTenancy()` or `withoutGlobalScope(CompanyScope::class)`
  in tenant-area code. These are only for platform, system and resolver code.
- Never accept `company_id` from a request. Use
  `app(TenantContext::class)->company()`.
- Livewire: never expose model IDs that authorize anything as public
  properties without re-checking them in the action. Use the
  `RequiresTenant` trait, and re-authorize inside every action.
- Queued jobs: pass the company id explicitly and set or bypass the context
  deliberately (the context is scoped per job).
- Add a cross-company test for every new tenant resource.

## Tests

`TenantIsolationTest`, `CrossCompanyManipulationTest`,
`BranchAuthorizationTest`, `CompanyMembershipTest` and `StaffPermissionsTest`
cover these guarantees.
