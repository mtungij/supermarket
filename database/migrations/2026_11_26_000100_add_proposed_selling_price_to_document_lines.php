<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: an OPTIONAL proposed selling price per purchase-order /
 * opening-stock line (per the line's unit). It is only a proposal: catalogue
 * prices change solely through an explicit, permission-checked action that
 * goes through PriceService (price history), and that action is recorded
 * here (applied_at / applied_by). Purchase costs never change prices.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['purchase_order_lines', 'opening_stock_lines'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->decimal('proposed_selling_price', 18, 2)->nullable();
                $t->timestamp('selling_price_applied_at')->nullable();
                $t->foreignId('selling_price_applied_by')->nullable()->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['purchase_order_lines', 'opening_stock_lines'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('selling_price_applied_by');
                $t->dropColumn(['proposed_selling_price', 'selling_price_applied_at']);
            });
        }
    }
};
