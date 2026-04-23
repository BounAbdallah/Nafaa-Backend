<?php

namespace Database\Seeders;

use App\Models\Expense;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use Illuminate\Database\Seeder;

class TestDataSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = 1;
        $userId   = 8;

        // 1. Créer des fournisseurs
        $suppliers = Supplier::factory()->count(5)->create([
            'tenant_id' => $tenantId,
        ]);

        // 2. Créer des produits (si besoin)
        $products = Product::where('tenant_id', $tenantId)->get();
        if ($products->isEmpty()) {
            $products = Product::factory()->count(10)->create([
                'tenant_id' => $tenantId,
            ]);
        }

        // 3. Créer des bons de commande
        foreach ($suppliers as $supplier) {
            $orders = PurchaseOrder::factory()->count(rand(2, 4))->create([
                'tenant_id'   => $tenantId,
                'supplier_id' => $supplier->id,
                'user_id'     => $userId,
            ]);

            foreach ($orders as $order) {
                $items = PurchaseOrderItem::factory()->count(rand(1, 5))->make([
                    'product_id' => $products->random()->id,
                ]);
                
                $order->items()->saveMany($items);
                $order->update(['total_amount' => $order->items()->sum(\DB::raw('quantity * unit_price'))]);
            }
        }

        // 4. Créer des dépenses
        Expense::factory()->count(15)->create([
            'tenant_id' => $tenantId,
            'user_id'   => $userId,
        ]);
    }
}
