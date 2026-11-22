<?php

namespace App\Livewire\Company;

use App\Enums\PlanLimit;
use App\Enums\PosDeviceStatus;
use App\Livewire\Concerns\RequiresTenant;
use App\Models\PosDevice;
use App\Models\StockLocation;
use App\Services\PosDeviceService;
use App\Services\SubscriptionLimiter;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Registers POS devices (registers/terminals) for branches.
 * The submitted branch UUID is re-validated against the branches the
 * current membership can access before anything is written.
 */
#[Layout('components.layouts.company')]
#[Title('POS devices')]
class PosDeviceManager extends Component
{
    use RequiresTenant;

    public string $name = '';

    public string $branch = '';

    public int $receiptPaperWidth = 80;

    public bool $showForm = false;

    public function mount(): void
    {
        $this->authorize('viewAny', PosDevice::class);
        $this->receiptPaperWidth = (int) config('pos.default_receipt_paper_width');
    }

    public function save(PosDeviceService $service, TenantContext $context, TenantResolver $resolver): void
    {
        $this->authorize('create', PosDevice::class);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'branch' => ['required', 'uuid'],
            'receiptPaperWidth' => ['required', 'integer', Rule::in(config('pos.receipt_paper_widths'))],
        ]);

        $branch = $resolver->accessibleBranches($context->membership())
            ->first(fn ($b) => hash_equals($b->uuid, $validated['branch']));

        if ($branch === null) {
            $this->addError('branch', __('Select a valid branch.'));

            return;
        }

        $this->authorize('create', [PosDevice::class, $branch]);

        $service->register($context->company(), $branch, $validated['name'], $validated['receiptPaperWidth'], auth()->user());

        $this->reset(['name', 'branch', 'showForm']);
        session()->flash('status', __('POS device registered.'));
    }

    /** Selling location + paper width per register. */
    public function configure(string $uuid, string $width, string $locationUuid, PosDeviceService $service): void
    {
        $device = PosDevice::query()->where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $device);
        $location = $locationUuid === '' ? null
            : StockLocation::query()->where('uuid', $locationUuid)->where('branch_id', $device->branch_id)->first() ?? abort(422);
        $service->configure($device, (int) $width, $location);
        session()->flash('status', __('Register settings saved.'));
    }

    public function revoke(string $uuid, PosDeviceService $service): void
    {
        // Tenant-scoped lookup: another company's device UUID is not found.
        $device = PosDevice::query()->where('uuid', $uuid)->first();

        if ($device === null) {
            throw new AuthorizationException;
        }

        $this->authorize('update', $device);
        $service->revoke($device);
    }

    public function render(): View
    {
        $context = app(TenantContext::class);
        $resolver = app(TenantResolver::class);
        $limiter = app(SubscriptionLimiter::class);
        $branches = $resolver->accessibleBranches($context->membership());

        return view('livewire.company.pos-device-manager', [
            'devices' => PosDevice::query()
                ->whereIn('branch_id', $branches->pluck('id'))
                ->with(['branch:id,name', 'stockLocation:id,uuid,name'])
                ->orderBy('name')
                ->get(),
            'branches' => $branches,
            'sellableLocations' => StockLocation::query()->whereIn('branch_id', $branches->pluck('id'))->where('is_active', true)->where('is_sellable', true)->get(['uuid', 'name', 'branch_id'])->groupBy('branch_id'),
            'widths' => config('pos.receipt_paper_widths'),
            'revoked' => PosDeviceStatus::Revoked,
            'limit' => $limiter->limit($context->company(), PlanLimit::PosDevices),
            'usage' => $limiter->usage($context->company(), PlanLimit::PosDevices),
            'canManage' => auth()->user()->can('create', PosDevice::class),
        ]);
    }
}
