<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // income = recette manuelle | withdrawal = retrait patron | contribution = apport
            $table->enum('type', ['income', 'withdrawal', 'contribution']);
            $table->string('label');           // ex : "Vente marché", "Retrait patron", "Apport capital"
            $table->decimal('amount', 14, 2);
            $table->string('payment_method')->default('cash');
            $table->date('movement_date');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'movement_date']);
        });

        // Catégories de dépenses personnalisées par tenant
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('slug')->index();
            $table->string('label');
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('cash_movements');
    }
};
