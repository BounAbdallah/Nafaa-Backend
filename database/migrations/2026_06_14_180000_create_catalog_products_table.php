<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_products', function (Blueprint $table) {
            $table->id();
            $table->string('barcode', 50);
            $table->string('country_code', 5);          // SN, GN, CI…
            $table->string('name');
            $table->string('brand')->nullable();
            $table->string('category')->nullable();     // clé catégorie interne Qiwam
            $table->string('default_unit', 20)->nullable();
            $table->string('image')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Un code-barres est unique PAR pays (même code peut différer selon le pays)
            $table->unique(['barcode', 'country_code']);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_products');
    }
};
