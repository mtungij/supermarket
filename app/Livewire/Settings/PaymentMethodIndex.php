<?php

namespace App\Livewire\Settings;

use App\Enums\PaymentMethodType;
use App\Livewire\Concerns\InteractsWithTenant;
use App\Models\PaymentMethod;
use App\Services\Pos\PaymentMethodService;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Company payment methods (all manually confirmed). */
#[Layout('components.layouts.tenant')]
#[Title('Payment methods')]
class PaymentMethodIndex extends Component
{
    use InteractsWithTenant;

    public bool $showForm = false;

    #[Locked]
    public ?string $editing = null;

    public string $name = '';

    public string $type = 'mobile_money';

    public bool $requiresReference = false;

    public bool $isActive = true;

    public int $sortOrder = 10;

    public function mount(): void
    {
        $this->authorize('payment_methods.manage');
    }

    public function create(): void
    {
        $this->reset(['editing', 'name', 'requiresReference', 'sortOrder']);
        $this->type = 'mobile_money';
        $this->isActive = true;
        $this->showForm = true;
    }

    public function edit(string $uuid): void
    {
        $m = PaymentMethod::query()->where('uuid', $uuid)->firstOrFail();
        [$this->editing, $this->name, $this->type, $this->requiresReference, $this->isActive, $this->sortOrder] = [$m->uuid, $m->name, $m->type->value, $m->requires_reference, $m->is_active, $m->sort_order];
        $this->showForm = true;
    }

    public function save(PaymentMethodService $service): void
    {
        $this->authorize('payment_methods.manage');
        $this->validate([
            'name' => ['required', 'string', 'max:60'],
            'type' => ['required', Rule::enum(PaymentMethodType::class)],
            'sortOrder' => ['integer', 'between:0,999'],
        ]);

        $method = $this->editing ? PaymentMethod::query()->where('uuid', $this->editing)->firstOrFail() : null;
        $service->save($this->company(), $method, $this->name, PaymentMethodType::from($this->type), $this->requiresReference, $this->isActive, $this->sortOrder);
        $this->showForm = false;
        session()->flash('status', __('Payment method saved.'));
    }

    public function render(): View
    {
        return view('livewire.settings.payment-method-index', [
            'methods' => PaymentMethod::query()->orderBy('sort_order')->orderBy('name')->get(),
            'types' => array_filter(PaymentMethodType::cases(), fn ($t) => $t !== PaymentMethodType::ExchangeCredit),
        ]);
    }
}
