<?php

use App\Enums\TaxCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('tax_code', 20)->default(TaxCode::Rate15->value)->after('tax_rate');
            $table->decimal('ice_rate', 5, 2)->default(0)->after('tax_code');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['tax_code', 'ice_rate']);
        });
    }
};
