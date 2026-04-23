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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('sku')->nullable();
            $table->text('description')->nullable();
            $table->enum('type', ['product', 'service'])->default('product');
            $table->string('category')->nullable();
            $table->string('unit')->default('pièce'); // pièce, kg, litre, heure, forfait…
            $table->decimal('selling_price', 15, 2)->default(0);
            $table->decimal('cost_price', 15, 2)->default(0);
            $table->integer('stock_quantity')->default(0);
            $table->integer('stock_alert')->default(5); // seuil d'alerte
            $table->string('image')->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
