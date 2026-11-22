<?php

namespace App\Livewire\Company;

use App\Enums\PlanLimit;
use App\Livewire\Concerns\RequiresTenant;
use App\Models\Branch;
use App\Services\BranchService;
use App\Services\SubscriptionLimiter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Lists the current company's branches and creates new ones.
 * The company is ALWAYS taken from the server-side tenant context; no
 * company identifier exists as a public (client-modifiable) property.
 */
#[Layout('components.layouts.company')]
#[Title('Branches')]
class BranchManager extends Component
{
    use RequiresTenant;

    public string $name = '';

    public string $code = '';

    public string $phone = '';

    public string $address = '';

    public bool $showForm = false;

    public function mount(): void
    {
        $this->authorize('viewAny', Branch::class);
    }

    public function save(BranchService $service, TenantContext $context): void
    {
        $this->authorize('create', Branch::class);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => [
                'nullable', 'string', 'max:20', 'alpha_dash',
                Rule::unique('branches', 'code')->where('company_id', $context->companyId()),
            ],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        $service->create($context->company(), $validated);

        $this->reset(['name', 'code', 'phone', 'address', 'showForm']);
        session()->flash('status', __('Branch created.'));
    }

    public function render(): View
    {
        $context = app(TenantContext::class);
        $limiter = app(SubscriptionLimiter::class);
        $membership = $context->membership();

        $branches = Branch::query()
            ->when(! $membership->all_branches, fn ($q) => $q->whereIn('id', $membership->branches()->select('branches.id')))
            ->withCount('posDevices')
            ->orderByDesc('is_head_office')
            ->orderBy('name')
            ->get();

        return view('livewire.company.branch-manager', [
            'branches' => $branches,
            'limit' => $limiter->limit($context->company(), PlanLimit::Branches),
            'usage' => $limiter->usage($context->company(), PlanLimit::Branches),
            'canCreate' => auth()->user()->can('create', Branch::class),
        ]);
    }
}
