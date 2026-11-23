<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opening stock: physical inventory that already existed before the shop
 * started using the system. Additive only.
 *
 *  - opening_stocks / opening_stock_lines: draft -> posted documents. Lines
 *    keep the ORIGINAL entered values (unit, quantity, cost per entered
 *    unit) next to the base-unit values that were posted.
 *  - opening_stock_registrations: one row per (company, location, product)
 *    ever opened. The UNIQUE index makes a second opening balance for the
 *    same initial-stock scope impossible, even under concurrent posting.
 *
 * Posting writes `opening_stock` stock movements through StockLedger; it
 * never creates purchase orders, goods receipts, payables or payments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_stocks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('location_id');
            $table->string('reference', 30);
            $table->string('status', 20);                    // draft | posted | cancelled
            $table->date('opening_date');
            $table->text('notes')->nullable();
            $table->string('source', 20)->default('manual'); // manual | import
            $table->string('import_filename')->nullable();
            $table->string('zero_cost_reason')->nullable();
            $table->unsignedInteger('line_count')->default(0);
            $table->decimal('total_quantity_base', 18, 4)->default(0);
            $table->decimal('total_value', 18, 4)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('zero_cost_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'reference']);
            $table->unique(['id', 'company_id']);
            $table->index(['company_id', 'status', 'branch_id']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->tenantForeign('branch_id', 'branches');
            $table->tenantForeign('location_id', 'stock_locations', 'branch_id');
        });

        Schema::create('opening_stock_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('opening_stock_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_unit_id');
            $table->decimal('conversion_factor', 18, 6);
            $table->decimal('quantity', 18, 4);              // in the entered unit
            $table->decimal('quantity_base', 18, 4);
            $table->decimal('unit_cost', 18, 4);             // per ENTERED unit, as typed
            $table->decimal('unit_cost_base', 18, 4);        // per base unit (posted cost)
            $table->decimal('line_value', 18, 4);            // quantity_base x unit_cost_base
            $table->boolean('zero_cost_confirmed')->default(false);
            $table->string('batch_number', 64)->nullable();
            $table->date('manufactured_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->unsignedBigInteger('batch_id')->nullable(); // resolved when posted
            $table->unsignedInteger('import_row')->nullable();
            $table->timestamps();

            $table->unique(['id', 'company_id']);
            $table->index(['opening_stock_id', 'product_id']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->tenantForeign('opening_stock_id', 'opening_stocks', onDelete: 'cascade');
            $table->tenantForeign('product_id', 'products');
            $table->tenantForeign('product_unit_id', 'product_units', 'product_id');
            $table->tenantForeign('batch_id', 'stock_batches', 'product_id');
        });

        Schema::create('opening_stock_registrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('opening_stock_id');
            $table->timestamp('created_at')->useCurrent();

            // THE duplicate guard: one opening balance per product per location.
            $table->unique(['company_id', 'location_id', 'product_id'], 'opening_stock_scope_unique');
            $table->index(['company_id', 'branch_id', 'product_id'], 'opening_stock_reg_branch_product_index');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->tenantForeign('opening_stock_id', 'opening_stocks');
            $table->tenantForeign('product_id', 'products');
            $table->tenantForeign('location_id', 'stock_locations');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_stock_registrations');
        Schema::dropIfExists('opening_stock_lines');
        Schema::dropIfExists('opening_stocks');
    }
};
