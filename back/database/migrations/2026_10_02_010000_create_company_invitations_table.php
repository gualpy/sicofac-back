<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email');
            $table->string('role');
            $table->string('token', 64)->unique();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['company_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_invitations');
    }
};
