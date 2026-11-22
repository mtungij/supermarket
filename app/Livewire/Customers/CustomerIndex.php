<?php

namespace App\Livewire\Customers;

use App\Livewire\Concerns\InteractsWithTenant;
use App\Models\Customer;
use App\Services\Pos\CustomerService;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.tenant')]
#[Title('Customers')]
class CustomerIndex extends Component
{
    use InteractsWithTenant, WithPagination;

    #[Url]
    public string $search = '';

    public bool $showForm = false;

    #[Locked]
    public ?string $editing = null;

    public array $form = ['name' => '', 'phone' => '', 'email' => '', 'notes' => '', 'is_active' => true];

    public function mount(): void
    {
        $this->authorize('customers.view');
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->authorize('customers.manage');
        $this->reset(['editing', 'form']);
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function edit(string $uuid): void
    {
        $this->authorize('customers.manage');
        $c = Customer::query()->where('uuid', $uuid)->firstOrFail();
        $this->editing = $c->uuid;
        $this->form = ['name' => $c->name, 'phone' => (string) $c->phone, 'email' => (string) $c->email, 'notes' => (string) $c->notes, 'is_active' => $c->is_active];
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function save(CustomerService $customers): void
    {
        $this->authorize('customers.manage');
        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:150'],
            'form.phone' => ['nullable', 'string', 'max:30'],
            'form.email' => ['nullable', 'email', 'max:255'],
            'form.notes' => ['nullable', 'string', 'max:2000'],
            'form.is_active' => ['boolean'],
        ], attributes: ['form.name' => __('name')])['form'];

        try {
            $customers->save($this->company(), $this->editing ? Customer::query()->where('uuid', $this->editing)->firstOrFail() : null, $data, $this->actor());
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ["form.{$k}" => $m])->all());
        }

        $this->showForm = false;
        session()->flash('status', __('Customer saved.'));
    }

    public function render(): View
    {
        $term = trim($this->search);

        return view('livewire.customers.customer-index', [
            'customers' => Customer::query()
                ->withCount('sales')->withSum('sales as sales_total', 'total')
                ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.addcslashes($term, '%_').'%')
                    ->orWhere('phone_key', 'like', '%'.(CustomerService::phoneKey($term) ?? '__none__').'%')
                    ->orWhere('email', 'like', '%'.addcslashes($term, '%_').'%')))
                ->orderBy('name')->paginate(25),
            'canManage' => auth()->user()->can('customers.manage'),
        ]);
    }
}
