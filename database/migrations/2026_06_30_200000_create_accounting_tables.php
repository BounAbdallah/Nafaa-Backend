<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Plan des comptes (SYSCOHADA simplifié) ────────────────────────
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 20);          // ex : "411", "601"
            $table->string('label');               // ex : "Clients"
            $table->unsignedTinyInteger('class');  // 1-7 SYSCOHADA
            // asset | liability | equity | revenue | expense | stock | treasury
            $table->string('nature', 20);
            $table->boolean('is_system')->default(false); // créé par le seeder
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'class']);
        });

        // ── En-têtes d'écritures ──────────────────────────────────────────
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference')->nullable();         // REF-2026-001
            $table->string('description');
            $table->date('entry_date');
            $table->string('period', 7);                    // "2026-06"
            // manual | sale | expense | cash_movement | purchase
            $table->string('source', 30)->default('manual');
            $table->unsignedBigInteger('source_id')->nullable(); // id de la vente/dépense/…
            $table->boolean('is_locked')->default(false);   // période clôturée
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'period']);
            $table->index(['tenant_id', 'source', 'source_id']);
        });

        // ── Lignes d'écritures (double entrée) ───────────────────────────
        Schema::create('journal_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);
            $table->string('label')->nullable();
            $table->timestamps();

            $table->index('journal_entry_id');
            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entry_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('accounts');
    }
};
