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
            $table->string('name');
            $table->string('trade_name')->nullable();
            $table->string('ruc', 13)->unique();
            $table->string('environment', 20)->default('test');
            $table->boolean('sri_signing_enabled')->default(false);
            $table->boolean('sri_submission_enabled')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};

