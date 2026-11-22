<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('email');
            $table->string('phone', 30);
            $table->text('address');
            $table->char('country_code', 2)->default('TZ');
            $table->char('currency', 3)->default('TZS');
            $table->string('timezone', 64)->default('Africa/Dar_es_Salaam');
            $table->string('locale', 5)->default('en');
            $table->string('status', 20)->default('active')->index();
            $table->json('settings')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
