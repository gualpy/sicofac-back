<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->unsignedInteger('price_cents');
            // null means unlimited.
            $table->unsignedInteger('monthly_document_limit')->nullable();
            $table->unsignedInteger('max_establishments')->nullable();
            $table->unsignedInteger('max_users')->nullable();
            $table->boolean('is_public')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();

        DB::table('plans')->insert([
            [
                'slug' => 'starter',
                'name' => 'Starter',
                'price_cents' => 4500,
                'monthly_document_limit' => 50,
                'max_establishments' => 1,
                'max_users' => 2,
                'is_public' => true,
                'sort_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'pro',
                'name' => 'Pro',
                'price_cents' => 8900,
                'monthly_document_limit' => 300,
                'max_establishments' => 3,
                'max_users' => 6,
                'is_public' => true,
                'sort_order' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => 'empresa',
                'name' => 'Empresa',
                'price_cents' => 0,
                'monthly_document_limit' => null,
                'max_establishments' => null,
                'max_users' => null,
                // Sales-assisted, not self-serve -- excluded from the public picker.
                'is_public' => false,
                'sort_order' => 3,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
