<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_account_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // qui a enregistré
            // credit = vente à crédit (+dette) | repayment = remboursement (-dette)
            // deposit = dépôt d'avance (+avance) | withdrawal = achat payé sur avance (-avance)
            $table->enum('type', ['credit', 'repayment', 'deposit', 'withdrawal']);
            $table->decimal('amount', 14, 2);          // toujours positif
            $table->decimal('balance_after', 14, 2);   // solde du client après l'opération
            $table->date('due_date')->nullable();      // échéance (crédit) — null = indéfinie
            $table->string('payment_method')->nullable(); // pour remboursement/dépôt : cash, wave…
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_account_entries');
    }
};
