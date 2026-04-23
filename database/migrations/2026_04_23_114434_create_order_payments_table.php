<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_payments', function (Blueprint $row) {
            $row->id();
            $row->foreignId('order_id')->constrained()->onDelete('cascade');
            $row->string('payment_method'); // cash, wave, orange_money, card, etc.
            $row->decimal('amount', 15, 2);
            $row->string('reference')->nullable(); // Numéro de transaction Wave/OM
            $row->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_payments');
    }
};
