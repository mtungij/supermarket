<?php

namespace App\Livewire\Purchasing;

use App\Livewire\Concerns\InteractsWithTenant;
use App\Models\Supplier;
use App\Services\Purchasing\SupplierService;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.tenant')]
#[Title('Suppliers')]
class SupplierIndex extends Component
{
    use InteractsWithTenant, WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = 'active';

    public bool $showForm = false;

    #[Locked]
    public ?string $editing = null;

    public array $form = [];

    public function mount(): void
    {
        $this->authorize('suppliers.view');
        $this->form = $this->blank();
    }

    private function blank(): array
    {
        return ['name' => '', 'phone' => '', 'email' => '', 'address' => '', 'tax_id' => '', 'contact_person' => '', 'notes' => '', 'is_active' => true];
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function create(): void
    {
        $this->authorize('suppliers.manage');
        [$this->editing, $this->form] = [null, $this->blank()];
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function edit(string $uuid): void
    {
        $this->authorize('suppliers.manage');
        $supplier = Supplier::query()->where('uuid', $uuid)->firstOrFail();
        $this->editing = $supplier->uuid;
        $this->form = array_merge($this->blank(), collect($supplier->only(array_keys($this->blank())))->map(fn ($v) => $v ?? '')->all());
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function save(SupplierService $service): void
    {
        $this->authorize('suppliers.manage');
        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:150'],
            'form.phone' => ['nullable', 'string', 'max:30'],
            'form.email' => ['nullable', 'email', 'max:255'],
            'form.address' => ['nullable', 'string', 'max:500'],
            'form.tax_id' => ['nullable', 'string', 'max:50'],
            'form.contact_person' => ['nullable', 'string', 'max:100'],
            'form.notes' => ['nullable', 'string', 'max:2000'],
            'form.is_active' => ['boolean'],
        ], attributes: ['form.name' => __('name')])['form'];

        $supplier = $this->editing ? Supplier::query()->where('uuid', $this->editing)->firstOrFail() : null;

        try {
            $service->save($this->company(), $supplier, $data);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ["form.{$k}" => $m])->all());
        }

        $this->showForm = false;
        session()->flash('status', __('Supplier saved.'));
    }

    public function render(): View
    {
        $term = addcslashes(trim($this->search), '%_');

        return view('livewire.purchasing.supplier-index', [
            'suppliers' => Supplier::query()
                ->withCount('purchaseOrders')
                ->withSum('transactions as balance', 'amount')
                ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%")->orWhere('contact_person', 'like', "%{$term}%")))
                ->when($this->status !== 'all', fn ($q) => $q->where('is_active', $this->status === 'active'))
                ->orderBy('name')->paginate(20),
            'canManage' => auth()->user()->can('suppliers.manage'),
        ]);
    }
}
