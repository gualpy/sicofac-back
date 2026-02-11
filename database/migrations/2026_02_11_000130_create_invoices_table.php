<?php

use App\Enums\InvoiceStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('document_code', 2)->default('01');
            $table->unsignedBigInteger('sequential');
            $table->date('issue_date');
            $table->string('status', 40)->default(InvoiceStatus::Draft->value);
            $table->string('currency', 3)->default('USD');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('tax', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('access_key', 49)->nullable()->unique();
            $table->string('sri_authorization_number')->nullable();
            $table->json('sri_response_payload')->nullable();
            $table->timestamp('xml_generated_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('authorized_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'document_code', 'sequential']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};

