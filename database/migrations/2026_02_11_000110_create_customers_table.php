<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('identification_type', 2)->default('05');
            $table->string('identification_number', 20);
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'identification_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};

