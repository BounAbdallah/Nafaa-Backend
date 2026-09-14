<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Prix d'abonnement personnalisé (remise manuelle) — null = prix du pack
            $table->decimal('custom_price', 12, 2)->nullable()->after('pack_id');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('custom_price');
        });
    }
};
