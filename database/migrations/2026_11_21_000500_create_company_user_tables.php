<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A user's membership in a company. One user may belong to many
        // companies; each membership carries its own role and status.
        Schema::create('company_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            // TRUE = may access every branch of the company.
            $table->boolean('all_branches')->default(false);
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'user_id']);
            $table->unique(['id', 'company_id']);
            $table->index(['user_id', 'is_active']);
        });

        // Branches a (branch-scoped) membership may access.
        Schema::create('branch_company_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('company_user_id');
            $table->unsignedBigInteger('branch_id');
            $table->timestamps();

            $table->unique(['company_user_id', 'branch_id']);
            $table->index(['company_id', 'branch_id']);

            // Composite FKs guarantee the membership and the branch belong
            // to the same company.
            $table->foreign(['company_user_id', 'company_id'])
                ->references(['id', 'company_id'])->on('company_user')->cascadeOnDelete();
            $table->foreign(['branch_id', 'company_id'])
                ->references(['id', 'company_id'])->on('branches')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_company_user');
        Schema::dropIfExists('company_user');
    }
};
