<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->decimal('default_vat_rate', 5, 2)->default(0)->after('settings');
            $table->string('vat_number')->nullable()->after('default_vat_rate');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['default_vat_rate', 'vat_number']);
        });
    }
};
