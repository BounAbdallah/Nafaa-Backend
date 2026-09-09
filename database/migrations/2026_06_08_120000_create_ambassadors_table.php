<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ambassadors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('referral_code', 12)->unique();
            $table->decimal('commission_rate', 5, 2)->default(10.00); // %
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->decimal('total_earnings', 12, 2)->default(0);
            $table->unsignedInteger('total_referrals')->default(0);
            $table->unsignedInteger('active_referrals')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->foreignId('ambassador_id')->nullable()->constrained('ambassadors')->nullOnDelete();
            $table->string('referral_code_used', 12)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ambassador_id');
            $table->dropColumn('referral_code_used');
        });
        Schema::dropIfExists('ambassadors');
    }
};
