<?php

use App\Enums\CompanyMembershipRole;
use App\Enums\CompanyMembershipStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_user', function (Blueprint $table) {
            $table->string('role', 20)->default(CompanyMembershipRole::Seller->value)->after('user_id');
            $table->string('status', 20)->default(CompanyMembershipStatus::Active->value)->after('role');
        });

        DB::table('company_user')->whereNull('role')->update([
            'role' => CompanyMembershipRole::Owner->value,
        ]);
        DB::table('company_user')->whereNull('status')->update([
            'status' => CompanyMembershipStatus::Active->value,
        ]);
    }

    public function down(): void
    {
        Schema::table('company_user', function (Blueprint $table) {
            $table->dropColumn(['role', 'status']);
        });
    }
};

