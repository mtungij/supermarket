<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchasing & receiving redesign. ADDITIVE ONLY: new nullable/defaulted
 * columns and one new table. Existing purchase orders and goods receipts
 * keep their values (new columns are NULL/0 for historical rows and the UI
 * falls back to the original fields).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotency keys: a double-clicked submit can never create two documents.
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->uuid('submission_key')->nullable()->after('reference');
            $table->unique(['company_id', 'submission_key']);
        });

        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->uuid('submission_key')->nullable()->after('reference');
            $table->unique(['company_id', 'submission_key']);
        });

        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->uuid('submission_key')->nullable()->after('reference');
            $table->string('delivery_note_number', 64)->nullable()->after('supplier_reference');
            $table->string('supplier_invoice_number', 64)->nullable()->after('delivery_note_number');
            $table->decimal('goods_value', 15, 2)->default(0)->after('total');        // net of discount, excl. tax
            $table->decimal('landed_cost_total', 15, 2)->default(0)->after('goods_value');
            $table->unique(['company_id', 'submission_key']);
        });

        Schema::table('goods_receipt_lines', function (Blueprint $table) {
            // Per-line receiving location (NULL = the receipt's default location).
            $table->unsignedBigInteger('location_id')->nullable()->after('product_unit_id');
            $table->decimal('goods_value', 15, 2)->default(0)->after('unit_cost_base');
            $table->decimal('tax_amount', 15, 2)->default(0)->after('goods_value');
            $table->decimal('landed_cost_amount', 15, 2)->default(0)->after('tax_amount');
            // Inventory cost per base unit = (goods value + landed cost) / base qty.
            $table->decimal('landed_unit_cost_base', 18, 4)->nullable()->after('landed_cost_amount');
            $table->string('notes', 500)->nullable()->after('batch_id');

            $table->foreign(['location_id', 'company_id'], 'goods_receipt_lines_location_fk')
                ->references(['id', 'company_id'])->on('stock_locations')->restrictOnDelete();
        });

        // Additional (landed) costs of a receipt: transport, handling, insurance...
        Schema::create('goods_receipt_costs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('goods_receipt_id');
            $table->string('cost_type', 30);
            $table->string('description', 150)->nullable();
            $table->decimal('amount', 15, 2);     // excl. VAT; NOT owed to the goods supplier
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->tenantForeign('goods_receipt_id', 'goods_receipts', onDelete: 'cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_costs');

        Schema::table('goods_receipt_lines', function (Blueprint $table) {
            $table->dropForeign('goods_receipt_lines_location_fk');
            $table->dropColumn(['location_id', 'goods_value', 'tax_amount', 'landed_cost_amount', 'landed_unit_cost_base', 'notes']);
        });

        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'submission_key']);
            $table->dropColumn(['submission_key', 'delivery_note_number', 'supplier_invoice_number', 'goods_value', 'landed_cost_total']);
        });

        foreach (['stock_transfers', 'purchase_orders'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropUnique(['company_id', 'submission_key']);
                $table->dropColumn('submission_key');
            });
        }
    }
};
