<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('subtotal_ht', 15, 2)->nullable()->after('subtotal');
            $table->decimal('vat_rate', 5, 2)->default(0)->after('tax_amount');
            $table->decimal('vat_amount', 15, 2)->default(0)->after('vat_rate');
        });

        // Backfill : commandes existantes sans TVA
        DB::statement('UPDATE orders SET subtotal_ht = total_amount, vat_rate = 0, vat_amount = 0 WHERE subtotal_ht IS NULL');
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['subtotal_ht', 'vat_rate', 'vat_amount']);
        });
    }
};
