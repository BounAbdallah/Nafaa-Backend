<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Rendez-vous ──────────────────────────────────────────────────────
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->enum('status', ['scheduled','confirmed','completed','cancelled'])->default('scheduled');
            $table->string('color', 7)->default('#3AA0D8'); // hex couleur calendrier
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'start_at']);
        });

        // ── Templates de documents ────────────────────────────────────────────
        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('type', ['quote', 'contract', 'invoice']);
            $table->longText('content'); // TipTap JSON
            $table->string('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        // ── Devis ─────────────────────────────────────────────────────────────
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference')->unique();
            $table->string('title');
            $table->longText('content')->nullable();    // TipTap JSON (corps du devis)
            $table->date('issued_at');
            $table->date('expires_at')->nullable();
            $table->enum('status', ['draft','sent','accepted','rejected','expired'])->default('draft');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);    // %
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('currency', 10)->default('FCFA');
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('quote_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->integer('sort_order')->default(0);
        });

        // ── Factures ──────────────────────────────────────────────────────────
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference')->unique();
            $table->string('title');
            $table->longText('content')->nullable();    // TipTap JSON
            $table->date('issued_at');
            $table->date('due_at')->nullable();
            $table->enum('status', ['draft','sent','paid','overdue','cancelled'])->default('draft');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('currency', 10)->default('FCFA');
            $table->date('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->integer('sort_order')->default(0);
        });

        // ── Contrats ──────────────────────────────────────────────────────────
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference')->unique();
            $table->string('title');
            $table->longText('content');               // TipTap JSON
            $table->date('signed_at')->nullable();
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->enum('status', ['draft','sent','signed','expired','cancelled'])->default('draft');
            $table->decimal('value', 12, 2)->nullable(); // valeur du contrat
            $table->string('currency', 10)->default('FCFA');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('quote_items');
        Schema::dropIfExists('quotes');
        Schema::dropIfExists('document_templates');
        Schema::dropIfExists('appointments');
    }
};
