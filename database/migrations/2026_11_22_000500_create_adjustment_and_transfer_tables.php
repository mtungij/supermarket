<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('location_id');
            $table->string('reference', 30);
            $table->string('reason', 30);
            $table->string('status', 30);
            $table->text('notes');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'reference']);
            $table->unique(['id', 'company_id']);
            $table->index(['company_id', 'status']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->tenantForeign('branch_id', 'branches');
            $table->tenantForeign('location_id', 'stock_locations', 'branch_id');
        });

        Schema::create('stock_adjustment_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('stock_adjustment_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_unit_id');
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->string('direction', 3); // in|out
            $table->decimal('conversion_factor', 18, 6);
            $table->decimal('quantity', 18, 4);
            $table->decimal('quantity_base', 18, 4);
            $table->decimal('unit_cost_base', 18, 4)->nullable(); // set when posted
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['id', 'company_id']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->tenantForeign('stock_adjustment_id', 'stock_adjustments', onDelete: 'cascade');
            $table->tenantForeign('product_id', 'products');
            $table->tenantForeign('product_unit_id', 'product_units', 'product_id');
            $table->tenantForeign('batch_id', 'stock_batches', 'product_id');
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->string('reference', 30);
            $table->unsignedBigInteger('source_branch_id');
            $table->unsignedBigInteger('source_location_id');
            $table->unsignedBigInteger('destination_branch_id');
            $table->unsignedBigInteger('destination_location_id');
            $table->string('status', 20);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dispatched_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'reference']);
            $table->unique(['id', 'company_id']);
            $table->index(['company_id', 'status']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['source_branch_id', 'company_id'], 'stock_transfers_source_branch_fk')->references(['id', 'company_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['destination_branch_id', 'company_id'], 'stock_transfers_dest_branch_fk')->references(['id', 'company_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['source_location_id', 'source_branch_id'], 'stock_transfers_source_location_fk')->references(['id', 'branch_id'])->on('stock_locations')->restrictOnDelete();
            $table->foreign(['destination_location_id', 'destination_branch_id'], 'stock_transfers_dest_location_fk')->references(['id', 'branch_id'])->on('stock_locations')->restrictOnDelete();
        });

        Schema::create('stock_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('stock_transfer_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_unit_id');
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->decimal('conversion_factor', 18, 6);
            $table->decimal('quantity', 18, 4);
            $table->decimal('quantity_base', 18, 4);
            $table->decimal('unit_cost_base', 18, 4)->nullable(); // source WAC at dispatch
            $table->timestamps();

            $table->unique(['id', 'company_id']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->tenantForeign('stock_transfer_id', 'stock_transfers', onDelete: 'cascade');
            $table->tenantForeign('product_id', 'products');
            $table->tenantForeign('product_unit_id', 'product_units', 'product_id');
            $table->tenantForeign('batch_id', 'stock_batches', 'product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_lines');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('stock_adjustment_lines');
        Schema::dropIfExists('stock_adjustments');
    }
};
