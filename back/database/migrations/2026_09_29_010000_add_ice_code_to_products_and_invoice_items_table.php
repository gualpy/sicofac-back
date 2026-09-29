<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // SRI Tabla 18 product-category code (e.g. "3072" = bebidas gaseosas),
            // required whenever ice_rate > 0 so RealXmlBuilder can emit the ICE
            // <impuesto> node with a valid codigoPorcentaje instead of refusing.
            $table->string('ice_code', 4)->nullable()->after('ice_rate');
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->string('ice_code', 4)->nullable()->after('ice_rate');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('ice_code');
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('ice_code');
        });
    }
};
