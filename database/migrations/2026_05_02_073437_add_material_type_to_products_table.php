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
        // On modifie la colonne type pour accepter 'material'
        \Illuminate\Support\Facades\DB::statement("ALTER TABLE products MODIFY COLUMN type ENUM('product', 'service', 'material') DEFAULT 'product'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        \Illuminate\Support\Facades\DB::statement("ALTER TABLE products MODIFY COLUMN type ENUM('product', 'service') DEFAULT 'product'");
    }
};
