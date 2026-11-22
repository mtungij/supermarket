<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: POS, sales, payments, customers, returns. ADDITIVE ONLY.
 *
 * There are deliberately NO shift tables: every sale records company,
 * branch, POS device, cashier and timestamp directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Which locations the POS may sell from. Existing Sales Floor
        // locations become sellable; Receiving Area / Main Store do not.
        Schema::table('stock_locations', function (Blueprint $table) {
            $table->boolean('is_sellable')->default(false)->after('type');
        });
        DB::table('stock_locations')->where('type', 'sales_floor')->update(['is_sellable' => true]);

        // Optional per-register selling location (NULL = branch default selling location).
        Schema::table('pos_devices', function (Blueprint $table) {
            $table->unique(['id', 'company_id']);              // target for sales.(pos_device_id, company_id)
            $table->unsignedBigInteger('stock_location_id')->nullable()->after('branch_id');
            $table->foreign(['stock_location_id', 'branch_id'], 'pos_devices_stock_location_fk')
                ->references(['id', 'branch_id'])->on('stock_locations')->restrictOnDelete();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->string('phone', 30)->nullable();
            $table->string('phone_key', 30)->nullable();     // digits only, for uniqueness/search
            $table->string('email')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'phone_key']);
            $table->unique(['id', 'company_id']);
            $table->index(['company_id', 'name']);
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name', 60);
            $table->string('code', 30);
            $table->string('type', 30);                       // PaymentMethodType
            $table->boolean('requires_reference')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->unique(['id', 'company_id']);
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('pos_device_id')->nullable();
            $table->unsignedBigInteger('location_id');        // selling location stock came from
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->string('sale_number', 30);
            $table->uuid('client_uuid');                      // idempotency key from the POS
            $table->char('currency', 3);
            $table->boolean('prices_include_tax');
            $table->decimal('subtotal', 15, 2);               // sum(qty x unit price)
            $table->decimal('discount_total', 15, 2);
            $table->decimal('tax_total', 15, 2);
            $table->decimal('total', 15, 2);                  // amount due = revenue incl. tax
            $table->decimal('net_total', 15, 2);              // revenue excl. tax
            $table->decimal('paid_total', 15, 2);             // applied payments (= total)
            $table->decimal('tendered_total', 15, 2);         // what the customer handed over
            $table->decimal('change_total', 15, 2);           // cash change (NOT revenue)
            $table->decimal('cost_total', 18, 4);             // COGS at posting
            $table->decimal('item_count', 18, 4);             // sum of entered quantities
            $table->unsignedBigInteger('exchange_return_id')->nullable()->unique(); // replacement sale of an exchange
            $table->string('notes', 500)->nullable();
            $table->timestamp('sold_at');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['company_id', 'sale_number']);
            $table->unique(['company_id', 'client_uuid']);
            $table->unique(['id', 'company_id']);
            $table->index(['company_id', 'branch_id', 'sold_at']);
            $table->index(['company_id', 'cashier_id', 'sold_at']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->tenantForeign('branch_id', 'branches');
            $table->tenantForeign('pos_device_id', 'pos_devices');
            $table->tenantForeign('location_id', 'stock_locations', 'branch_id');
            $table->tenantForeign('customer_id', 'customers');
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('sale_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_unit_id');
            // Snapshots: history never depends on today's catalogue.
            $table->string('product_name', 150);
            $table->string('sku', 64);
            $table->string('barcode', 64)->nullable();
            $table->string('unit_name', 50);
            $table->decimal('conversion_factor', 18, 6);
            $table->decimal('quantity', 18, 4);
            $table->decimal('quantity_base', 18, 4);
            $table->decimal('unit_price', 15, 2);
            $table->string('price_source', 30);
            $table->unsignedBigInteger('product_price_id')->nullable();
            $table->decimal('discount_amount', 15, 2);
            $table->string('tax_class', 20);
            $table->decimal('tax_rate', 5, 2);
            $table->decimal('tax_amount', 15, 2);
            $table->decimal('net_amount', 15, 2);             // excl. tax, after discount
            $table->decimal('line_total', 15, 2);             // incl. tax, after discount
            $table->decimal('cost_amount', 18, 4);            // COGS for this line
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['id', 'company_id']);
            $table->unique(['id', 'sale_id']);
            $table->index(['company_id', 'product_id']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->tenantForeign('sale_id', 'sales');
            $table->tenantForeign('product_id', 'products');
            $table->tenantForeign('product_unit_id', 'product_units', 'product_id');
        });

        // One row per lot (batch) a sale line was taken from: the source of each stock movement.
        Schema::create('sale_item_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('sale_item_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->decimal('quantity_base', 18, 4);
            $table->decimal('unit_cost', 18, 4);              // WAC at sale (per base unit)
            $table->decimal('cost_amount', 18, 4);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['id', 'company_id']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->tenantForeign('sale_item_id', 'sale_items');
            $table->tenantForeign('batch_id', 'stock_batches', 'product_id');
        });

        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('sale_id');
            $table->unsignedBigInteger('payment_method_id');
            $table->string('method_name', 60);                // snapshot
            $table->string('method_type', 30);
            $table->decimal('amount', 15, 2);                 // applied to the sale
            $table->decimal('tendered', 15, 2);               // handed over
            $table->decimal('change_given', 15, 2);           // cash only
            $table->string('reference', 100)->nullable();
            $table->boolean('confirmed_manually')->default(true);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'payment_method_id']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->tenantForeign('sale_id', 'sales');
            $table->tenantForeign('payment_method_id', 'payment_methods');
        });

        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('sale_id');
            $table->string('return_number', 30);
            $table->uuid('client_uuid');
            $table->foreignId('processed_by')->constrained('users')->restrictOnDelete();
            $table->string('reason', 255);
            $table->unsignedBigInteger('refund_method_id');
            $table->string('refund_method_name', 60);
            $table->string('refund_method_type', 30);
            $table->string('refund_reference', 100)->nullable();
            $table->decimal('refund_total', 15, 2);           // incl. tax
            $table->decimal('tax_total', 15, 2);
            $table->decimal('net_total', 15, 2);
            $table->decimal('cost_total', 18, 4);             // COGS reversed at original cost
            $table->timestamp('returned_at');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['company_id', 'return_number']);
            $table->unique(['company_id', 'client_uuid']);
            $table->unique(['id', 'company_id']);
            $table->index(['company_id', 'branch_id', 'returned_at']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->tenantForeign('branch_id', 'branches');
            $table->tenantForeign('sale_id', 'sales');
            $table->tenantForeign('refund_method_id', 'payment_methods');
        });

        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('sale_return_id');
            $table->unsignedBigInteger('sale_item_id');
            $table->unsignedBigInteger('sale_item_allocation_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->unsignedBigInteger('location_id');        // where the goods went back to
            $table->string('disposition', 20);                // restock|damaged|expired
            $table->decimal('quantity_base', 18, 4);
            $table->decimal('refund_amount', 15, 2);
            $table->decimal('tax_amount', 15, 2);
            $table->decimal('unit_cost', 18, 4);              // original sale cost per base unit
            $table->decimal('cost_amount', 18, 4);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['id', 'company_id']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->tenantForeign('sale_return_id', 'sale_returns');
            $table->tenantForeign('sale_item_id', 'sale_items');
            $table->tenantForeign('sale_item_allocation_id', 'sale_item_allocations');
            $table->tenantForeign('location_id', 'stock_locations');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreign(['exchange_return_id', 'company_id'], 'sales_exchange_return_fk')
                ->references(['id', 'company_id'])->on('sale_returns')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales', fn (Blueprint $t) => $t->dropForeign('sales_exchange_return_fk'));
        Schema::dropIfExists('sale_return_items');
        Schema::dropIfExists('sale_returns');
        Schema::dropIfExists('sale_payments');
        Schema::dropIfExists('sale_item_allocations');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('customers');
        Schema::table('pos_devices', function (Blueprint $table) {
            $table->dropForeign('pos_devices_stock_location_fk');
            $table->dropColumn('stock_location_id');
            $table->dropUnique(['id', 'company_id']);
        });
        Schema::table('stock_locations', fn (Blueprint $t) => $t->dropColumn('is_sellable'));
    }
};
