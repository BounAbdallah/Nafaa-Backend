<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $row) {
            $row->id();
            $row->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $row->foreignId('customer_id')->nullable()->constrained()->onDelete('set null');
            $row->foreignId('user_id')->constrained()->onDelete('cascade');
            
            $row->string('reference')->unique();
            $row->enum('status', ['pending', 'completed', 'cancelled'])->default('completed');
            $row->enum('payment_status', ['unpaid', 'partial', 'paid'])->default('paid');
            $row->string('payment_method')->default('cash'); // Pour le POS, on stockera peut-être une chaîne combinée ou une table de paiements plus tard si besoin
            
            $row->decimal('subtotal', 15, 2)->default(0);
            $row->decimal('tax_amount', 15, 2)->default(0);
            $row->decimal('discount_amount', 15, 2)->default(0);
            $row->decimal('total_amount', 15, 2)->default(0);
            $row->decimal('paid_amount', 15, 2)->default(0);
            $row->decimal('change_amount', 15, 2)->default(0);
            
            $row->text('notes')->nullable();
            $row->timestamps();
            $row->softDeletes();
        });

        Schema::create('order_items', function (Blueprint $row) {
            $row->id();
            $row->foreignId('order_id')->constrained()->onDelete('cascade');
            $row->foreignId('product_id')->nullable()->constrained()->onDelete('set null');
            
            $row->string('description');
            $row->decimal('quantity', 15, 3);
            $row->decimal('unit_price', 15, 2);
            $row->decimal('subtotal', 15, 2);
            
            $row->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
