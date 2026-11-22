<?php

namespace Tests\Feature\Inventory;

use App\Enums\AdjustmentReason;
use App\Enums\LocationType;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidDocumentStateException;
use App\Models\Branch;
use App\Models\CompanyUser;
use App\Models\GoodsReceipt;
use App\Models\PaymentMethod;
use App\Models\PosDevice;
use App\Models\Sale;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventory\StockAdjustmentService;
use App\Services\Pos\CheckoutService;
use App\Services\Purchasing\GoodsReceiptService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\CreatesInventory;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Real concurrency: two forked PHP processes with their OWN MySQL
 * connections race on the same document / stock row. Data is committed
 * (no RefreshDatabase transaction); tearDown truncates and re-seeds the
 * dedicated test database so other tests see a clean, seeded schema.
 */
#[Group('concurrency')]
class ConcurrentStockPostingTest extends TestCase
{
    use CreatesInventory, CreatesTenants;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork') || DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires pcntl and MySQL.');
        }

        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh', ['--seed' => true]);
            RefreshDatabaseState::$migrated = true;
        }

        $this->dir = sys_get_temp_dir().'/sm-concurrency-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        // Leave the TEST database exactly as RefreshDatabase expects it.
        DB::reconnect();
        Schema::disableForeignKeyConstraints();
        foreach (DB::select('SHOW TABLES') as $row) {
            $table = array_values((array) $row)[0];
            if ($table !== 'migrations') {
                DB::table($table)->truncate();
            }
        }
        Schema::enableForeignKeyConstraints();
        $this->seed();

        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);

        parent::tearDown();
    }

    public function test_concurrent_posting_of_the_same_grn_adds_stock_once(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);
        $juice = $this->makeProduct($company, $owner);
        $piece = $this->baseUnitOf($juice);
        $order = $this->approvedOrder($company, $this->headOffice($company), $this->makeSupplier($company), [[$juice, $piece, '50', '1000']], $owner);
        $grn = app(GoodsReceiptService::class)->createDraft($order, [
            'location_id' => $this->location($this->headOffice($company))->id, 'received_date' => today()->toDateString(),
        ], [['purchase_order_line_id' => $order->lines()->sole()->id, 'product_unit_id' => $piece->id, 'quantity' => '50']], $owner);

        $results = $this->race(2, fn () => app(GoodsReceiptService::class)->post(GoodsReceipt::forCompany($company)->findOrFail($grn->id), $this->asUser($owner->id, $company->id)));

        sort($results);
        $this->assertSame(['duplicate', 'ok'], $results);
        $this->assertSame('50.0000', $this->onHand($this->location($this->headOffice($company)), $juice));
        $this->assertSame(1, StockMovement::forCompany($company)->count());
    }

    public function test_concurrent_issues_never_drive_stock_negative(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);
        $juice = $this->makeProduct($company, $owner);
        $piece = $this->baseUnitOf($juice);
        $location = $this->location($this->headOffice($company));
        $order = $this->approvedOrder($company, $this->headOffice($company), $this->makeSupplier($company), [[$juice, $piece, '10', '1000']], $owner);
        $this->receiveGoods($order, [['purchase_order_line_id' => $order->lines()->sole()->id, 'product_unit_id' => $piece->id, 'quantity' => '10']], $owner, $location);

        $ids = collect([1, 2])->map(fn () => app(StockAdjustmentService::class)->request($location, AdjustmentReason::Damaged, 'Race test', [
            ['product_id' => $juice->id, 'product_unit_id' => $piece->id, 'quantity' => '7'],
        ], $owner)->id)->all();

        $results = $this->race(2, fn (int $i) => app(StockAdjustmentService::class)->approve(
            StockAdjustment::forCompany($company)->findOrFail($ids[$i]), $this->asUser($owner->id, $company->id),
        ));

        sort($results);
        $this->assertSame(['insufficient', 'ok'], $results);
        $this->assertSame('3.0000', $this->onHand($location, $juice));
    }

    public function test_concurrent_checkouts_never_oversell(): void
    {
        $company = $this->createCompany();
        $owner = $this->createOwner($company);
        $this->actingAsTenant($owner, $company);
        $soap = $this->makeProduct($company, $owner, ['selling_price' => '2500']);
        $piece = $this->baseUnitOf($soap);
        $floor = $this->location($this->headOffice($company), LocationType::SalesFloor);
        $order = $this->approvedOrder($company, $this->headOffice($company), $this->makeSupplier($company), [[$soap, $piece, '6', '1000']], $owner);
        $this->receiveGoods($order, [['purchase_order_line_id' => $order->lines()->sole()->id, 'product_unit_id' => $piece->id, 'quantity' => '6']], $owner, $floor);
        $device = new PosDevice(['name' => 'Till', 'receipt_paper_width' => 80]);
        $device->forceFill(['company_id' => $company->id, 'branch_id' => $this->headOffice($company)->id, 'status' => 'active'])->save();
        $cash = PaymentMethod::forCompany($company)->where('code', 'cash')->value('id');

        // Two cashiers each try to sell 4 of the last 6 at the same moment.
        $results = $this->race(2, fn () => app(CheckoutService::class)->checkout(
            $company->fresh(), $this->headOffice($company), $device->fresh(), $this->asUser($owner->id, $company->id), (string) Str::uuid(),
            [['product_id' => $soap->id, 'product_unit_id' => $piece->id, 'quantity' => '4']], [['payment_method_id' => $cash, 'amount' => '10000']],
        ));

        sort($results);
        $this->assertSame(['insufficient', 'ok'], $results);
        $this->assertSame('2.0000', $this->onHand($floor, $soap));
        $this->assertSame(1, Sale::forCompany($company)->count());

        // The same cart submitted twice at once (same idempotency key): one sale.
        $key = (string) Str::uuid();
        $this->race(2, fn () => app(CheckoutService::class)->checkout(
            $company->fresh(), $this->headOffice($company), $device->fresh(), $this->asUser($owner->id, $company->id), $key,
            [['product_id' => $soap->id, 'product_unit_id' => $piece->id, 'quantity' => '1']], [['payment_method_id' => $cash, 'amount' => '2500']],
        ));

        $this->assertSame(2, Sale::forCompany($company)->count());
        $this->assertSame('1.0000', $this->onHand($floor, $soap));
    }

    /** Rebuilds auth + tenant context inside a child process. */
    private function asUser(int $userId, int $companyId): User
    {
        $user = User::query()->findOrFail($userId);
        Auth::setUser($user);
        $membership = CompanyUser::query()->where('user_id', $userId)->where('company_id', $companyId)->with(['company', 'role.permissions'])->firstOrFail();
        app(TenantContext::class)->set($membership->company, $membership, Branch::withoutTenancy()->where('company_id', $companyId)->where('is_head_office', true)->first());

        return $user;
    }

    /**
     * Fork $n children that start the callback at the same moment.
     *
     * @return list<string> ok | duplicate | insufficient | error:<message>
     */
    private function race(int $n, callable $work): array
    {
        DB::disconnect(); // children must not share the parent's socket
        $go = $this->dir.'/go';
        $pids = [];

        for ($i = 0; $i < $n; $i++) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                DB::purge();
                while (! file_exists($go)) {
                    usleep(1000);
                }

                try {
                    $work($i);
                    $result = 'ok';
                } catch (InvalidDocumentStateException) {
                    $result = 'duplicate';
                } catch (InsufficientStockException) {
                    $result = 'insufficient';
                } catch (\Throwable $e) {
                    $result = 'error:'.get_class($e).':'.$e->getMessage();
                }

                file_put_contents("{$this->dir}/result-{$i}", $result);
                posix_kill(getmypid(), SIGKILL); // skip shutdown handlers in the child
            }

            $pids[] = $pid;
        }

        touch($go);

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        DB::reconnect();

        return array_map(fn ($i) => (string) @file_get_contents("{$this->dir}/result-{$i}"), range(0, $n - 1));
    }
}
