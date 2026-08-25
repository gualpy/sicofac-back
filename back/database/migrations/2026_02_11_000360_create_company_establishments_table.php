<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_establishments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 3);
            $table->string('name');
            $table->string('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('company_emission_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_establishment_id')->constrained()->cascadeOnDelete();
            $table->string('code', 3);
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_establishment_id', 'code']);
        });

        // Backfill one establishment + emission point per existing company,
        // matching its legacy single establishment_code/emission_point, so
        // nothing breaks for companies created before this table existed.
        $companies = DB::table('companies')->select('id', 'name', 'trade_name', 'establishment_code', 'emission_point')->get();

        foreach ($companies as $company) {
            $establishmentId = DB::table('company_establishments')->insertGetId([
                'company_id' => $company->id,
                'code' => $company->establishment_code,
                'name' => $company->trade_name ?: $company->name,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('company_emission_points')->insert([
                'company_establishment_id' => $establishmentId,
                'code' => $company->emission_point,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_emission_points');
        Schema::dropIfExists('company_establishments');
    }
};
