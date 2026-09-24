<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('pos_enabled')->default(false)->after('is_active');
            // nullOnDelete: removing a POS category should not take the product with it.
            $table->foreignId('pos_category_id')->nullable()->after('pos_enabled')
                ->constrained('pos_categories')->nullOnDelete();
            $table->string('pos_label')->nullable()->after('pos_category_id');
            $table->string('barcode')->nullable()->after('pos_label');
            $table->integer('pos_sort_order')->default(0)->after('barcode');

            $table->unique(['company_id', 'barcode']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'barcode']);
            $table->dropConstrainedForeignId('pos_category_id');
            $table->dropColumn(['pos_enabled', 'pos_label', 'barcode', 'pos_sort_order']);
        });
    }
};
