<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2: product catalogue. Every table is company-scoped and every
 * cross-table reference uses a composite (x_id, company_id) foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('key', 30);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();

            $table->unique(['company_id', 'key']);
        });

        Schema::create('document_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('documentable_type', 50);
            $table->unsignedBigInteger('documentable_id');
            $table->string('event', 40);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['documentable_type', 'documentable_id']);
            $table->index(['company_id', 'created_at']);
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name', 50);
            $table->string('code', 20);
            $table->string('dimension', 20); // count|mass|volume|length
            // Ratio to the dimension's reference unit (kg, l, m). NULL for count units.
            $table->decimal('ratio', 20, 9)->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->unique(['id', 'company_id']);
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name', 100);
            $table->string('name_key', 100); // normalised for uniqueness
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'name_key']);
            $table->unique(['id', 'company_id']);
            $table->tenantForeign('parent_id', 'categories');
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('name_key', 100);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'name_key']);
            $table->unique(['id', 'company_id']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->unsignedBigInteger('base_unit_id');
            $table->string('name', 150);
            $table->string('sku', 64);
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->string('product_type', 30);
            $table->string('tax_class', 20);
            // Latest purchase cost per BASE unit (informational; valuation uses inventory_costs).
            $table->decimal('purchase_cost', 18, 4)->default(0);
            // Default selling price per BASE unit (company-wide fallback).
            $table->decimal('selling_price', 15, 2)->default(0);
            $table->decimal('min_stock_level', 18, 4)->default(0);
            $table->decimal('reorder_level', 18, 4)->default(0);
            $table->boolean('track_expiry')->default(false);
            $table->boolean('track_batches')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'sku']);
            $table->unique(['id', 'company_id']);
            $table->index(['company_id', 'is_active', 'name']);
            $table->tenantForeign('category_id', 'categories');
            $table->tenantForeign('brand_id', 'brands');
            $table->tenantForeign('base_unit_id', 'units');
        });

        // Product-specific conversions: 1 <unit> = factor x <base unit>.
        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('unit_id');
            $table->decimal('factor', 18, 6);
            $table->boolean('is_base')->default(false);
            $table->boolean('can_purchase')->default(true);
            $table->boolean('can_sell')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['product_id', 'unit_id']);
            $table->unique(['id', 'product_id']);
            $table->unique(['id', 'company_id']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->tenantForeign('product_id', 'products');
            $table->tenantForeign('unit_id', 'units');
        });

        Schema::create('product_barcodes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_unit_id');
            $table->string('barcode', 64);
            $table->string('type', 20); // manufacturer|internal|packaging
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            // A barcode identifies exactly one product+unit inside a company.
            $table->unique(['company_id', 'barcode']);
            $table->index(['product_id', 'is_primary']);
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->tenantForeign('product_id', 'products');
            $table->tenantForeign('product_unit_id', 'product_units', 'product_id');
        });

        // Append-only, effective-dated price list. Latest effective_from <= now wins.
        Schema::create('product_prices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_unit_id');
            $table->unsignedBigInteger('branch_id')->nullable(); // NULL = all branches
            $table->decimal('price', 15, 2);
            $table->timestamp('effective_from');
            $table->string('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_id', 'product_unit_id', 'branch_id', 'effective_from'], 'product_prices_lookup_index');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->tenantForeign('product_id', 'products');
            $table->tenantForeign('product_unit_id', 'product_units', 'product_id');
            $table->tenantForeign('branch_id', 'branches');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_prices');
        Schema::dropIfExists('product_barcodes');
        Schema::dropIfExists('product_units');
        Schema::dropIfExists('products');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('units');
        Schema::dropIfExists('document_events');
        Schema::dropIfExists('document_sequences');
    }
};
