<?php

namespace App\Livewire\Inventory;

use App\Livewire\Concerns\InteractsWithTenant;
use App\Models\Branch;
use App\Models\StockLocation;
use App\Services\Inventory\OpeningStockImport as Importer;
use App\Services\Inventory\OpeningStockService;
use App\Support\SpreadsheetReader;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Bulk opening stock: upload CSV/XLSX -> map columns -> validation preview
 * (row-level errors, totals) -> create ONE draft only when every row is
 * valid -> post it from the draft page (same posting service, same
 * permissions and duplicate protection as manual entry).
 */
#[Layout('components.layouts.tenant')]
#[Title('Import opening stock')]
class OpeningStockImport extends Component
{
    use InteractsWithTenant, WithFileUploads;

    public $file = null;

    public string $branch = '';

    public string $location = '';

    public string $openingDate = '';

    public string $notes = '';

    /** @var list<string> */
    public array $headers = [];

    /** @var array<string, string> field => column index */
    public array $mapping = [];

    public bool $confirmZeroCost = false;

    public string $zeroCostReason = '';

    public ?array $result = null;

    public function mount(): void
    {
        $this->authorize('inventory.opening.manage');
        $this->branch = $this->currentBranch()->uuid;
        $this->openingDate = today($this->company()->timezone ?: config('saas.default_timezone'))->toDateString();
        $this->location = (string) $this->locations()->firstWhere('is_sellable', true)?->uuid;
    }

    private function branchModel(): ?Branch
    {
        $branch = Branch::query()->where('uuid', $this->branch)->first();

        return $branch && $this->canAccessBranch($branch->id) ? $branch : null;
    }

    private function locations()
    {
        $branch = $this->branchModel();

        return $branch ? StockLocation::query()->where('branch_id', $branch->id)->where('is_active', true)->orderByDesc('is_sellable')->orderBy('name')->get() : collect();
    }

    private function locationModel(): ?StockLocation
    {
        return $this->locations()->firstWhere('uuid', $this->location);
    }

    public function updatedBranch(): void
    {
        $this->location = (string) $this->locations()->firstWhere('is_sellable', true)?->uuid;
        $this->result = null;
    }

    public function updatedLocation(): void
    {
        $this->result = null;
    }

    /** @return list<list<string>> */
    private function rows(): array
    {
        $this->validate(['file' => ['required', 'file', 'max:5120', 'mimes:csv,txt,xlsx']], [], ['file' => __('File')]);

        return SpreadsheetReader::read($this->file->getRealPath(), $this->file->getClientOriginalName());
    }

    public function updatedFile(Importer $importer): void
    {
        $this->result = null;
        $this->headers = [];
        $rows = $this->rows();
        $this->headers = $rows[0] ?? [];
        $this->mapping = $importer->autoMap($this->headers);
    }

    public function analyse(Importer $importer): void
    {
        $this->authorize('inventory.opening.manage');
        $location = $this->locationModel();
        if ($location === null) {
            $this->addError('location', __('Select a stock location.'));

            return;
        }

        $rows = $this->rows();
        array_shift($rows);
        $this->result = $importer->analyse($this->company(), $location, $rows, $this->mapping, $this->confirmZeroCost);
        unset($this->result['lines']);   // never kept client-side; re-validated on create
    }

    public function createDraft(Importer $importer, OpeningStockService $service): mixed
    {
        $this->authorize('inventory.opening.manage');
        $this->validate(['openingDate' => ['required', 'date_format:Y-m-d'], 'notes' => ['nullable', 'string', 'max:2000'], 'zeroCostReason' => ['nullable', 'string', 'max:255']]);
        $location = $this->locationModel() ?? throw ValidationException::withMessages(['location' => __('Select a stock location.')]);

        // Re-read and re-validate the file server-side; all-or-nothing.
        $rows = $this->rows();
        array_shift($rows);
        $analysis = $importer->analyse($this->company(), $location, $rows, $this->mapping, $this->confirmZeroCost);
        if ($analysis['errors'] !== []) {
            unset($analysis['lines']);
            $this->result = $analysis;
            $this->addError('file', __('Fix the errors in the file first. Nothing was saved.'));

            return null;
        }
        if ($analysis['totals']['zero_cost_rows'] > 0 && blank($this->zeroCostReason)) {
            $this->addError('zeroCostReason', __('Explain why some opening stock has no cost.'));

            return null;
        }

        $draft = $service->saveDraft($this->company(), null, [
            'branch_id' => $location->branch_id, 'location_id' => $location->id, 'opening_date' => $this->openingDate,
            'notes' => $this->notes, 'zero_cost_reason' => $this->zeroCostReason,
        ], $analysis['lines'], $this->actor(), 'import', mb_substr($this->file->getClientOriginalName(), 0, 200));

        session()->flash('status', __('Draft :ref created from :rows rows. Review it, then post it. Stock has not changed yet.', ['ref' => $draft->reference, 'rows' => $draft->line_count]));

        return $this->redirectRoute('inventory.opening-stock.show', $draft, navigate: false);
    }

    public function render(): View
    {
        return view('livewire.inventory.opening-stock-import', [
            'branches' => $this->accessibleBranches(),
            'locations' => $this->locations(),
            'fields' => array_keys(Importer::FIELDS),
            'currency' => $this->company()->currency,
            'canZero' => auth()->user()->can('inventory.opening.zero_cost'),
        ]);
    }
}
