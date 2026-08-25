<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('xml_generated_path')->nullable();
            $table->string('xml_signed_path')->nullable();
            $table->string('xml_authorized_path')->nullable();
            $table->timestamps();

            $table->unique('invoice_id');
            $table->index(['invoice_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_documents');
    }
};
