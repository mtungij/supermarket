<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: an optional REFERENCE buying price entered when a product is
 * registered (e.g. the supplier's usual price). It is only a hint (default
 * unit cost on new purchase-order lines). It is deliberately separate from
 * products.purchase_cost, which is the latest ACTUAL cost written by posted
 * goods receipts and used as a costing fallback; inventory valuation still
 * comes only from goods receipts / the weighted-average cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('reference_buying_price', 18, 4)->nullable()->after('purchase_cost');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('reference_buying_price');
        });
    }
};
