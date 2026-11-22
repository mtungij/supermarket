<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('supplier_id');
            $table->string('reference', 30);
            $table->string('status', 30);
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->char('currency', 3);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'reference']);
            $table->unique(['id', 'company_id']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'supplier_id']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->tenantForeign('branch_id', 'branches');
            $table->tenantForeign('supplier_id', 'suppliers');
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('purchase_order_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_unit_id');
            $table->decimal('conversion_factor', 18, 6);  // snapshot
            $table->decimal('quantity', 18, 4);           // in ordered unit
            $table->decimal('quantity_base', 18, 4);      // snapshot in base units
            $table->decimal('unit_cost', 18, 4);          // per ordered unit, excl. tax
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('line_subtotal', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);
            $table->decimal('received_quantity_base', 18, 4)->default(0);
            $table->timestamps();

            $table->unique(['id', 'company_id']);
            $table->unique(['id', 'purchase_order_id']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->tenantForeign('purchase_order_id', 'purchase_orders', onDelete: 'cascade');
            $table->tenantForeign('product_id', 'products');
            $table->tenantForeign('product_unit_id', 'product_units', 'product_id');
        });

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('purchase_order_id');
            $table->unsignedBigInteger('supplier_id');
            $table->string('reference', 30);
            $table->string('status', 20);
            $table->date('received_date');
            $table->string('supplier_reference', 64)->nullable(); // delivery note / invoice no.
            $table->text('notes')->nullable();
            $table->decimal('total', 15, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'reference']);
            $table->unique(['id', 'company_id']);
            $table->index(['company_id', 'purchase_order_id']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->tenantForeign('branch_id', 'branches');
            $table->tenantForeign('location_id', 'stock_locations', 'branch_id');
            $table->tenantForeign('purchase_order_id', 'purchase_orders');
            $table->tenantForeign('supplier_id', 'suppliers');
        });

        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('goods_receipt_id');
            $table->unsignedBigInteger('purchase_order_id');
            $table->unsignedBigInteger('purchase_order_line_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_unit_id');
            $table->decimal('conversion_factor', 18, 6);
            $table->decimal('quantity', 18, 4);
            $table->decimal('quantity_base', 18, 4);
            $table->decimal('unit_cost', 18, 4);       // per received unit
            $table->decimal('unit_cost_base', 18, 4);  // per base unit (cost snapshot)
            $table->decimal('line_total', 15, 2);
            $table->string('batch_number', 64)->nullable();
            $table->date('manufactured_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->timestamps();

            $table->unique(['id', 'company_id']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->tenantForeign('goods_receipt_id', 'goods_receipts', onDelete: 'cascade');
            $table->tenantForeign('purchase_order_line_id', 'purchase_order_lines', 'purchase_order_id');
            $table->tenantForeign('product_id', 'products');
            $table->tenantForeign('product_unit_id', 'product_units', 'product_id');
            $table->tenantForeign('batch_id', 'stock_batches', 'product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
    }
};
