<?php

namespace App\Livewire\Inventory;

use App\Enums\LocationType;
use App\Livewire\Concerns\InteractsWithTenant;
use App\Models\StockBalance;
use App\Models\StockLocation;
use App\Services\Inventory\StockLocationService;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.tenant')]
#[Title('Stock locations')]
class LocationIndex extends Component
{
    use InteractsWithTenant;

    public bool $showForm = false;

    public string $name = '';

    public string $code = '';

    public string $type = 'other';

    public function mount(): void
    {
        $this->authorize('inventory.view');
    }

    public function save(StockLocationService $service): void
    {
        $this->authorize('inventory.locations.manage');
        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:20'],
            'type' => ['required', Rule::enum(LocationType::class)],
        ]);

        $service->create($this->currentBranch(), $this->name, $this->code, LocationType::from($this->type));
        $this->reset(['name', 'code', 'showForm']);
        session()->flash('status', __('Location saved.'));
    }

    public function toggle(string $uuid, StockLocationService $service): void
    {
        $this->authorize('inventory.locations.manage');
        $location = StockLocation::query()->where('uuid', $uuid)->where('branch_id', $this->currentBranch()->id)->firstOrFail();
        $service->setActive($location, ! $location->is_active);
    }

    public function toggleSellable(string $uuid, StockLocationService $service): void
    {
        $this->authorize('inventory.locations.manage');
        $location = StockLocation::query()->where('uuid', $uuid)->where('branch_id', $this->currentBranch()->id)->firstOrFail();
        $service->setSellable($location, ! $location->is_sellable);
    }

    public function render(): View
    {
        $branch = $this->currentBranch();

        return view('livewire.inventory.location-index', [
            'branch' => $branch,
            'locations' => StockLocation::query()->where('branch_id', $branch->id)->orderBy('type')->orderBy('name')->get(),
            'counts' => StockBalance::query()->where('branch_id', $branch->id)->where('quantity', '>', 0)
                ->selectRaw('location_id, count(distinct product_id) as products')->groupBy('location_id')->pluck('products', 'location_id'),
            'types' => LocationType::cases(),
            'canManage' => auth()->user()->can('inventory.locations.manage'),
        ]);
    }
}
