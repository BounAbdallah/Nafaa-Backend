<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('industry');
            $table->string('profile_type'); // manufacturer, reseller, wholesaler, service_provider
            $table->string('plan')->default('demarrage'); // demarrage, pro, business, entreprise
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->string('logo')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->string('stripe_id')->nullable()->index();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('plan_expires_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
