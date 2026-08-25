<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->softDeletes()->after('updated_at');
            $table->index(['company_id', 'deleted_at']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->softDeletes()->after('updated_at');
            $table->index(['company_id', 'deleted_at']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->softDeletes()->after('updated_at');
            $table->index(['company_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'deleted_at']);
            $table->dropSoftDeletes();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'deleted_at']);
            $table->dropSoftDeletes();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'deleted_at']);
            $table->dropSoftDeletes();
        });
    }
};

