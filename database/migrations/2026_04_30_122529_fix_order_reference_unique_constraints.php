<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropUnique(['reference']);
            $table->unique(['tenant_id', 'reference']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['reference']);
            $table->unique(['tenant_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'reference']);
            $table->unique('reference');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'reference']);
            $table->unique('reference');
        });
    }
};
