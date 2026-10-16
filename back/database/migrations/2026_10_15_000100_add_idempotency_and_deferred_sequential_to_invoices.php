<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // G2: the fiscal number is assigned at emission, so drafts have none.
            $table->unsignedBigInteger('sequential')->nullable()->change();

            // G1: idempotent creation from API clients (FitOS).
            $table->string('external_reference', 100)->nullable()->after('company_id');
            $table->string('idempotency_key', 100)->nullable()->after('external_reference');
            $table->char('request_hash', 64)->nullable()->after('idempotency_key');

            $table->unique(['company_id', 'idempotency_key'], 'invoices_company_idempotency_unique');
            $table->unique(['company_id', 'external_reference'], 'invoices_company_external_ref_unique');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique('invoices_company_idempotency_unique');
            $table->dropUnique('invoices_company_external_ref_unique');
            $table->dropColumn(['external_reference', 'idempotency_key', 'request_hash']);
            // Fails if drafts without a sequential exist: issue or delete them first.
            $table->unsignedBigInteger('sequential')->nullable(false)->change();
        });
    }
};
