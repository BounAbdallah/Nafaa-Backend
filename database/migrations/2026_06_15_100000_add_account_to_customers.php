<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Solde du compte client : > 0 = avance (la boutique lui doit),
            //                          < 0 = dette/ardoise (le client doit)
            $table->decimal('account_balance', 14, 2)->default(0)->after('total_spent');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('account_balance');
        });
    }
};
