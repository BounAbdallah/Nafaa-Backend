<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL : ALTER COLUMN via raw SQL ; SQLite : no-op (string columns accept any value)
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE products MODIFY COLUMN type ENUM('product', 'service', 'material') DEFAULT 'product'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE products MODIFY COLUMN type ENUM('product', 'service') DEFAULT 'product'");
        }
    }
};
