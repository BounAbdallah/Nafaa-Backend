<?php

namespace App\Services\Ai\Tools;

use App\Models\Product;

/**
 * Read-only: looks up a product's current stock.
 */
class QueryStockTool implements AiTool
{
    public function name(): string
    {
        return 'query_stock';
    }

    public function schema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name'        => $this->name(),
                'description' => "Renvoie le stock actuel d'un produit (par nom ou SKU).",
                'parameters'  => [
                    'type' => 'object',
                    'properties' => [
                        'product' => [
                            'type'        => 'string',
                            'description' => 'Nom commercial ou SKU.',
                        ],
                    ],
                    'required' => ['product'],
                ],
            ],
        ];
    }

    public function execute(array $args): array
    {
        $needle   = (string) ($args['product'] ?? '');
        $tenantId = (int) auth()->user()?->tenant_id;

        if ($needle === '' || ! $tenantId) {
            return ['ok' => false, 'error' => 'Paramètres invalides.'];
        }

        $product = Product::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($q) use ($needle) {
                $q->where('sku', $needle)
                  ->orWhere('name', 'like', '%'.$needle.'%');
            })
            ->first();

        if (! $product) {
            return ['ok' => false, 'error' => "Produit introuvable : « {$needle} »."];
        }

        return [
            'ok'           => true,
            'product_name' => $product->name,
            'sku'          => $product->sku,
            'stock'        => $product->stock_quantity,
            'alert_level'  => $product->stock_alert,
            'is_low'       => $product->stock_quantity <= $product->stock_alert,
            'message'      => "Stock de « {$product->name} » : {$product->stock_quantity} unité(s)"
                            . ($product->stock_quantity <= $product->stock_alert ? ' ⚠️ stock bas.' : '.'),
        ];
    }
}
