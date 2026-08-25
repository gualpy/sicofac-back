<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('document_code', 2);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();

            $table->unique(['company_id', 'document_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};

