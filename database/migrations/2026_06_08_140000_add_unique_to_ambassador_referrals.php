<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ambassador_referrals', function (Blueprint $table) {
            // Prevent duplicate pending + active records for same pair
            $table->unique(['ambassador_id', 'tenant_id'], 'amb_referrals_unique');
        });
    }

    public function down(): void
    {
        Schema::table('ambassador_referrals', function (Blueprint $table) {
            $table->dropUnique('amb_referrals_unique');
        });
    }
};
