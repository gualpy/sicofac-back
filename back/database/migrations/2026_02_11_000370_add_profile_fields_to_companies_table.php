<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('email');
            $table->boolean('is_rimpe')->default(false)->after('logo_path');
            $table->boolean('is_special_taxpayer')->default(false)->after('is_rimpe');
            $table->boolean('is_popular_business')->default(false)->after('is_special_taxpayer');
            $table->boolean('requires_accounting')->default(false)->after('is_popular_business');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['logo_path', 'is_rimpe', 'is_special_taxpayer', 'is_popular_business', 'requires_accounting']);
        });
    }
};
