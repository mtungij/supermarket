<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: an opening-stock document line is either
 *  - 'opening'    initial stock of a product never opened at that location
 *                 and with no other activity in the branch (registered in
 *                 opening_stock_registrations, movement type opening_stock), or
 *  - 'additional' "Additional stock in" for a product that already has stock
 *                 or an opening balance there (movement type stock_in; never
 *                 registered, never disguised as opening stock).
 * Existing lines are all 'opening' (the default).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opening_stock_lines', function (Blueprint $table) {
            $table->string('line_type', 20)->default('opening')->after('product_unit_id');
        });
    }

    public function down(): void
    {
        Schema::table('opening_stock_lines', function (Blueprint $table) {
            $table->dropColumn('line_type');
        });
    }
};
