<?php

namespace App\Livewire\Catalogue;

use App\Enums\UnitDimension;
use App\Livewire\Concerns\InteractsWithTenant;
use App\Models\Product;
use App\Models\Unit;
use App\Services\Catalogue\UnitService;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.tenant')]
#[Title('Units')]
class UnitIndex extends Component
{
    use InteractsWithTenant;

    public bool $showForm = false;

    public string $name = '';

    public string $code = '';

    public string $dimension = 'count';

    public string $ratio = '';

    public function mount(): void
    {
        $this->authorize('products.view');
    }

    public function save(UnitService $service): void
    {
        $this->authorize('units.manage');
        $this->validate([
            'name' => ['required', 'string', 'max:50'],
            'code' => ['required', 'string', 'max:20'],
            'dimension' => ['required', Rule::enum(UnitDimension::class)],
            'ratio' => ['nullable', 'string', 'max:30'],
        ]);

        $service->create($this->company(), $this->name, $this->code, UnitDimension::from($this->dimension), $this->ratio ?: null);
        $this->reset(['name', 'code', 'ratio', 'showForm']);
        session()->flash('status', __('Unit saved.'));
    }

    public function toggle(string $uuid, UnitService $service): void
    {
        $this->authorize('units.manage');
        $unit = Unit::query()->where('uuid', $uuid)->firstOrFail();
        $service->setActive($unit, ! $unit->is_active);
    }

    public function render(): View
    {
        $units = Unit::query()->orderBy('dimension')->orderBy('name')->get();
        $usage = Product::query()->selectRaw('base_unit_id, count(*) as total')->groupBy('base_unit_id')->pluck('total', 'base_unit_id');

        return view('livewire.catalogue.unit-index', [
            'units' => $units,
            'usage' => $usage,
            'dimensions' => UnitDimension::cases(),
            'canManage' => auth()->user()->can('units.manage'),
        ]);
    }
}
