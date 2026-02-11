<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('scope_type')->default('company');
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('path');
            $table->string('status', 20)->default('pending');
            $table->boolean('is_active')->default(false);
            $table->text('password_encrypted');
            $table->timestamp('uploaded_at');
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'version']);
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_certificates');
    }
};
