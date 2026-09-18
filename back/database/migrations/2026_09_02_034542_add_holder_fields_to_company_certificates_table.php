<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('company_certificates', function (Blueprint $table) {
            // Identity data embedded in Ecuadorian personal certs (custom
            // OID extensions under 1.3.6.1.4.1.59382.3.*: RUC, nombres,
            // apellidos, telefono) -- captured at upload so it can be
            // synced onto the Company when the certificate is activated.
            $table->string('holder_ruc', 13)->nullable()->after('subject');
            $table->string('holder_name')->nullable()->after('holder_ruc');
            $table->string('holder_phone', 30)->nullable()->after('holder_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_certificates', function (Blueprint $table) {
            $table->dropColumn(['holder_ruc', 'holder_name', 'holder_phone']);
        });
    }
};
