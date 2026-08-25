<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_xml_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->longText('xml_content');
            $table->timestamps();

            $table->index(['invoice_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_xml_documents');
    }
};

