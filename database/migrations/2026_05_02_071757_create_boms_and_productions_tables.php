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
        // Bill of Materials (Recettes)
        Schema::create('boms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->string('name')->nullable();
            $table->decimal('quantity', 15, 3)->default(1); // Rendement de base (ex: pour 1 unité ou 100 unités)
            $table->decimal('waste_percentage', 5, 2)->default(0); // Perte automatique
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Items de la recette (Ingrédients)
        Schema::create('bom_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bom_id')->constrained()->onDelete('cascade');
            $table->foreignId('ingredient_id')->constrained('products')->onDelete('cascade');
            $table->decimal('quantity', 15, 3);
            $table->timestamps();
        });

        // Ordres de Fabrication (Productions)
        Schema::create('productions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->foreignId('bom_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            $table->string('reference')->unique();
            $table->string('batch_number')->nullable();
            $table->date('expiry_date')->nullable();
            
            $table->decimal('planned_quantity', 15, 3);
            $table->decimal('actual_quantity', 15, 3)->nullable();
            $table->decimal('waste_quantity', 15, 3)->default(0);
            
            $table->string('status')->default('pending'); // pending, in_progress, completed, cancelled
            $table->decimal('total_cost', 15, 2)->nullable();
            
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('productions');
        Schema::dropIfExists('bom_items');
        Schema::dropIfExists('boms');
    }
};
