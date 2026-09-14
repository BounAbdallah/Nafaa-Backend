<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pack_country_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pack_id')->constrained()->cascadeOnDelete();
            $table->string('country_code', 5);          // ex: GN, SN, CI
            $table->decimal('price', 14, 2);            // prix dans la devise locale
            $table->string('currency', 5)->default('XOF'); // ex: GNF, XOF, MAD
            $table->timestamps();

            $table->unique(['pack_id', 'country_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pack_country_prices');
    }
};
