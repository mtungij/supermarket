<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_locations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->string('name', 100);
            $table->string('code', 20);
            $table->string('type', 30); // main_store|sales_floor|receiving_area|other
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['branch_id', 'code']);
            $table->unique(['id', 'company_id']);
            $table->unique(['id', 'branch_id']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->tenantForeign('branch_id', 'branches');
        });

        Schema::create('stock_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('product_id');
            $table->string('batch_number', 64);
            $table->date('manufactured_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'batch_number']);
            $table->unique(['id', 'product_id']);
            $table->unique(['id', 'company_id']);
            $table->index(['company_id', 'expires_on']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->tenantForeign('product_id', 'products');
        });

        // Current quantity per location/product/batch (BASE units).
        // Written ONLY by App\Services\Inventory\StockLedger.
        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('batch_id')->nullable();
            // NULL-safe uniqueness: MySQL treats NULLs as distinct in unique indexes.
            $table->unsignedBigInteger('batch_key')->storedAs('IFNULL(`batch_id`, 0)');
            $table->decimal('quantity', 18, 4)->default(0);
            $table->timestamps();

            $table->unique(['location_id', 'product_id', 'batch_key']);
            $table->index(['company_id', 'branch_id', 'product_id']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->tenantForeign('branch_id', 'branches');
            $table->tenantForeign('location_id', 'stock_locations', 'branch_id');
            $table->tenantForeign('product_id', 'products');
            $table->tenantForeign('batch_id', 'stock_batches', 'product_id');
        });

        // Weighted-average cost per branch & product (BASE units).
        Schema::create('inventory_costs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('quantity', 18, 4)->default(0);
            $table->decimal('average_cost', 18, 4)->default(0);
            $table->decimal('last_cost', 18, 4)->default(0);
            $table->timestamps();

            $table->unique(['branch_id', 'product_id']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->tenantForeign('branch_id', 'branches');
            $table->tenantForeign('product_id', 'products');
        });

        // Immutable stock ledger. One row per stock change; never updated or deleted.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->string('type', 30);
            $table->decimal('quantity', 18, 4);            // signed, BASE units
            $table->decimal('unit_cost', 18, 4);           // cost per BASE unit used for this movement
            $table->decimal('total_cost', 18, 4);          // signed
            $table->decimal('balance_after', 18, 4);       // location+batch balance after
            $table->decimal('branch_quantity_after', 18, 4);
            $table->decimal('average_cost_after', 18, 4);  // branch WAC after
            // Snapshot of what the user entered.
            $table->unsignedBigInteger('product_unit_id')->nullable();
            $table->decimal('entered_quantity', 18, 4)->nullable();
            $table->decimal('conversion_factor', 18, 6)->nullable();
            $table->string('source_type', 50);
            $table->unsignedBigInteger('source_id');
            $table->string('reference', 40)->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            // Idempotency: one movement of a given type per source line.
            $table->unique(['company_id', 'source_type', 'source_id', 'type'], 'stock_movements_source_unique');
            $table->index(['company_id', 'branch_id', 'product_id', 'occurred_at'], 'stock_movements_product_index');
            $table->index(['company_id', 'type', 'occurred_at']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->tenantForeign('branch_id', 'branches');
            $table->tenantForeign('location_id', 'stock_locations', 'branch_id');
            $table->tenantForeign('product_id', 'products');
            $table->tenantForeign('batch_id', 'stock_batches', 'product_id');
            $table->tenantForeign('product_unit_id', 'product_units', 'product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('inventory_costs');
        Schema::dropIfExists('stock_balances');
        Schema::dropIfExists('stock_batches');
        Schema::dropIfExists('stock_locations');
    }
};
