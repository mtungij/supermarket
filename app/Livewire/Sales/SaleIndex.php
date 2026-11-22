<?php

namespace App\Livewire\Sales;

use App\Livewire\Concerns\InteractsWithTenant;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Pos\MySalesReport;
use App\Services\Pos\SalesQueries;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Sales page with two views over the SAME scoped data:
 *  - Products Sold (default for cashiers): what was sold, how many, for how much
 *  - Transactions (default for managers): sale numbers, payments, status, reprint
 *
 * Every query (cards, products, transactions) starts from
 * SalesQueries::visibleTo(): cashiers see ONLY their own sales; managers
 * their permitted branches; owners the whole company. Filter parameters can
 * only narrow that scope, never widen it. Reporting only, no shifts.
 */
#[Layout('components.layouts.tenant')]
#[Title('Sales')]
class SaleIndex extends Component
{
    use InteractsWithTenant, WithPagination;

    #[Url]
    public string $tab = '';

    #[Url]
    public string $period = 'today';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $search = '';

    #[Url]
    public string $branch = '';

    #[Url]
    public string $cashier = '';

    #[Url]
    public string $customer = '';

    #[Url]
    public string $method = '';

    public function mount(): void
    {
        $user = auth()->user();
        abort_unless($user->can('sales.view') || $user->can('sales.create'), 403);

        if (! in_array($this->tab, ['products', 'transactions'], true)) {
            $this->tab = $user->can('sales.view') ? 'transactions' : 'products';
        }

        if (! in_array($this->period, MySalesReport::PERIODS, true)) {
            $this->period = 'today';
        }
    }

    public function updating(): void
    {
        $this->resetPage();
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab === 'transactions' ? 'transactions' : 'products';
        $this->resetPage();
    }

    public function setPeriod(string $period): void
    {
        $this->period = in_array($period, MySalesReport::PERIODS, true) ? $period : 'today';
        $this->resetPage();
    }

    /** Scoped base query + filters that can only NARROW the scope. */
    private function scoped(User $user, SalesQueries $queries): Builder
    {
        return $queries->visibleTo($user)
            ->when($this->branch !== '', fn ($q) => $q->whereHas('branch', fn ($b) => $b->where('uuid', $this->branch)))
            ->when($this->cashier !== '' && $user->can('sales.view'), fn ($q) => $q->where('sales.cashier_id', (int) $this->cashier));
    }

    public function render(SalesQueries $queries, MySalesReport $report): View
    {
        $tz = $this->company()->timezone ?: config('saas.default_timezone');
        $user = auth()->user();

        try {
            [$from, $to] = $report->range($this->period, $tz, $this->from, $this->to);
        } catch (\InvalidArgumentException) {
            $this->period = 'today';
            [$from, $to] = $report->range('today', $tz);
        }

        $scoped = $this->scoped($user, $queries);
        $term = addcslashes(trim($this->search), '%_');

        return view('livewire.sales.sale-index', [
            'summary' => $report->build($scoped, $from, $to),
            'products' => $this->tab === 'products' ? $report->productsSold($scoped, $from, $to) : collect(),
            'sales' => $this->tab === 'transactions'
                ? (clone $scoped)->whereBetween('sales.sold_at', [$from, $to])
                    ->with(['branch:id,name', 'cashier:id,name', 'customer:id,name', 'payments:id,sale_id,method_name,amount'])
                    ->withCount('items')
                    ->withSum('returns as returned_total', 'refund_total')
                    ->when($term !== '', fn ($q) => $q->where('sale_number', 'like', '%'.strtoupper($term).'%'))
                    ->when($this->customer !== '', fn ($q) => $q->whereHas('customer', fn ($c) => $c->where('name', 'like', '%'.addcslashes($this->customer, '%_').'%')))
                    ->when($this->method !== '', fn ($q) => $q->whereHas('payments', fn ($p) => $p->where('payment_method_id', PaymentMethod::query()->where('uuid', $this->method)->value('id') ?? 0)))
                    ->latest('sold_at')->latest('id')
                    ->paginate(25)
                : null,
            'branches' => $this->accessibleBranches(),
            'cashiers' => $user->can('sales.view')
                ? User::query()->whereIn('id', $queries->visibleTo($user)->select('cashier_id')->distinct())->orderBy('name')->get(['id', 'name'])
                : collect(),
            'methods' => PaymentMethod::query()->orderBy('sort_order')->get(['uuid', 'name']),
            'range' => [$from->setTimezone($tz), $to->setTimezone($tz)],
            'tz' => $tz,
            'currency' => $this->company()->currency,
            'scopeOwn' => ! $user->can('sales.view'),
        ]);
    }
}
