<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('establishment_code', 3)->default('001')->after('ruc');
            $table->string('emission_point', 3)->default('001')->after('establishment_code');
            $table->string('certificate_path')->nullable()->after('emission_point');
            $table->string('certificate_name')->nullable()->after('certificate_path');
            $table->timestamp('certificate_uploaded_at')->nullable()->after('certificate_name');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('establishment_code', 3)->default('001')->after('document_code');
            $table->string('emission_point', 3)->default('001')->after('establishment_code');
            $table->timestamp('issue_requested_at')->nullable()->after('authorized_at');
            $table->dropUnique(['company_id', 'document_code', 'sequential']);
            $table->unique(['company_id', 'document_code', 'establishment_code', 'emission_point', 'sequential'], 'invoices_company_doc_estab_pto_seq_unique');
        });

        Schema::table('document_sequences', function (Blueprint $table) {
            $table->string('establishment_code', 3)->default('001')->after('document_code');
            $table->string('emission_point', 3)->default('001')->after('establishment_code');
            $table->dropUnique(['company_id', 'document_code']);
            $table->unique(['company_id', 'document_code', 'establishment_code', 'emission_point'], 'doc_seq_company_doc_estab_pto_unique');
        });
    }

    public function down(): void
    {
        Schema::table('document_sequences', function (Blueprint $table) {
            $table->dropUnique('doc_seq_company_doc_estab_pto_unique');
            $table->unique(['company_id', 'document_code']);
            $table->dropColumn(['establishment_code', 'emission_point']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique('invoices_company_doc_estab_pto_seq_unique');
            $table->unique(['company_id', 'document_code', 'sequential']);
            $table->dropColumn(['establishment_code', 'emission_point', 'issue_requested_at']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'establishment_code',
                'emission_point',
                'certificate_path',
                'certificate_name',
                'certificate_uploaded_at',
            ]);
        });
    }
};
