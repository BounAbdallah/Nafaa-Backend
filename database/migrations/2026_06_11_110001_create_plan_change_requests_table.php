<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('current_pack_id')->nullable()->constrained('packs')->nullOnDelete();
            $table->foreignId('requested_pack_id')->constrained('packs')->cascadeOnDelete();
            $table->text('note')->nullable();          // message du client
            $table->string('status')->default('pending'); // pending | approved | rejected
            $table->text('admin_note')->nullable();    // réponse de l'admin
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_change_requests');
    }
};
