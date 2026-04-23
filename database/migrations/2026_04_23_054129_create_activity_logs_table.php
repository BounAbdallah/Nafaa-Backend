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
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');           // ex: user.invited, user.blocked, tenant.created
            $table->string('subject_type')->nullable(); // ex: App\Models\User
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('properties')->nullable(); // données contextuelles
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
