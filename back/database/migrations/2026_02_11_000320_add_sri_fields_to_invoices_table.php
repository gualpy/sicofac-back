<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('guide_number', 17)->nullable()->after('emission_point');
            $table->boolean('is_negotiable')->default(false)->after('guide_number');

            // Bucketed IVA subtotals, matching the SRI totals summary.
            $table->decimal('subtotal_15', 12, 2)->default(0)->after('discount');
            $table->decimal('subtotal_5', 12, 2)->default(0)->after('subtotal_15');
            $table->decimal('subtotal_special', 12, 2)->default(0)->after('subtotal_5');
            $table->decimal('subtotal_zero', 12, 2)->default(0)->after('subtotal_special');
            $table->decimal('subtotal_not_subject', 12, 2)->default(0)->after('subtotal_zero');
            $table->decimal('subtotal_exempt', 12, 2)->default(0)->after('subtotal_not_subject');
            $table->decimal('tax_15', 12, 2)->default(0)->after('subtotal_exempt');
            $table->decimal('tax_5', 12, 2)->default(0)->after('tax_15');
            $table->decimal('tax_special', 12, 2)->default(0)->after('tax_5');
            $table->decimal('ice_total', 12, 2)->default(0)->after('tax_special');
            $table->boolean('has_tip')->default(false)->after('ice_total');
            $table->decimal('tip_amount', 12, 2)->default(0)->after('has_tip');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'guide_number',
                'is_negotiable',
                'subtotal_15',
                'subtotal_5',
                'subtotal_special',
                'subtotal_zero',
                'subtotal_not_subject',
                'subtotal_exempt',
                'tax_15',
                'tax_5',
                'tax_special',
                'ice_total',
                'has_tip',
                'tip_amount',
            ]);
        });
    }
};
