<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Registered POS terminals/registers. Future offline sales are
        // attributed to (company, branch, device, cashier, timestamp).
        // There is deliberately no shift relationship.
        Schema::create('pos_devices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->string('name', 100);
            // Stable identifier generated on the device during pairing (future).
            $table->string('device_identifier', 100)->nullable();
            $table->string('status', 20)->default('active');
            $table->unsignedTinyInteger('receipt_paper_width')->default(80); // 58 | 80
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['branch_id', 'company_id'])
                ->references(['id', 'company_id'])->on('branches')->restrictOnDelete();

            $table->unique(['company_id', 'device_identifier']);
            $table->index(['company_id', 'branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_devices');
    }
};
