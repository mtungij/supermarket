<?php

namespace App\Livewire\Sales;

use App\Livewire\Concerns\InteractsWithTenant;
use App\Services\Pos\SalesReport;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Branch- or company-scoped sales report (COGS / profit need reports.financial). */
#[Layout('components.layouts.tenant')]
#[Title('Sales reports')]
class SalesReports extends Component
{
    use InteractsWithTenant;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $branch = '';

    public function mount(): void
    {
        $this->authorize('reports.view');
        $tz = $this->company()->timezone;
        $this->from = $this->from ?: today($tz)->startOfMonth()->toDateString();
        $this->to = $this->to ?: today($tz)->toDateString();
    }

    public function render(SalesReport $report): View
    {
        $tz = $this->company()->timezone;
        $branches = $this->accessibleBranches();
        $ids = $this->branch === '' ? $branches->pluck('id')->all() : $branches->where('uuid', $this->branch)->pluck('id')->all();

        try {
            $from = Carbon::parse($this->from, $tz)->startOfDay()->utc();
            $to = Carbon::parse($this->to, $tz)->endOfDay()->utc();
        } catch (\Throwable) {
            [$from, $to] = [today($tz)->startOfDay()->utc(), today($tz)->endOfDay()->utc()];
        }

        return view('livewire.sales.sales-reports', [
            'report' => $report->build($this->company()->id, $ids, $from, $to),
            'branches' => $branches,
            'financial' => auth()->user()->can('reports.financial'),
        ]);
    }
}
