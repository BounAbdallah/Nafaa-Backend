<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ambassador_referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ambassador_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('client_name');
            $table->string('client_email');
            $table->string('subscription_plan')->nullable();
            $table->decimal('subscription_amount', 10, 2)->default(0); // monthly price FCFA
            $table->decimal('commission_rate', 5, 2)->default(0);      // % at time of referral
            $table->decimal('commission_amount', 10, 2)->default(0);   // calculated amount
            $table->enum('status', ['pending', 'active', 'cancelled'])->default('pending');
            $table->boolean('commission_paid')->default(false);
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ambassador_referrals');
    }
};
