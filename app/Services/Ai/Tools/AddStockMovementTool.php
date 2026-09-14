<?php

namespace App\Services\Ai\Tools;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Adds (or removes) a quantity to a product's stock.
 * Resolves the product by SKU or name (fuzzy match).
 */
class AddStockMovementTool implements AiTool
{
    public function name(): string
    {
        return 'add_stock_movement';
    }

    public function schema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name'        => $this->name(),
                'description' => "Ajoute (ou retire) une quantité au stock d'un produit. "
                              .  "Utilise le nom commercial OU le SKU. Quantité positive = entrée, négative = sortie.",
                'parameters'  => [
                    'type' => 'object',
                    'properties' => [
                        'product' => [
                            'type'        => 'string',
                            'description' => 'Nom du produit ou SKU (ex: "Tissu Bazin Premium" ou "REF-TBZ-001")',
                        ],
                        'quantity' => [
                            'type'        => 'integer',
                            'description' => 'Quantité à ajouter (>0) ou retirer (<0).',
                        ],
                        'reason' => [
                            'type'        => 'string',
                            'description' => 'Motif court (ex: "réception fournisseur", "inventaire", "casse")',
                        ],
                    ],
                    'required' => ['product', 'quantity'],
                ],
            ],
        ];
    }

    public function execute(array $args): array
    {
        $needle   = (string) ($args['product']  ?? '');
        $quantity = (int)    ($args['quantity'] ?? 0);
        $reason   = (string) ($args['reason']   ?? 'mouvement manuel');

        if ($needle === '' || $quantity === 0) {
            return ['ok' => false, 'error' => 'product et quantity sont requis et non nuls.'];
        }

        $tenantId = (int) auth()->user()?->tenant_id;
        if (! $tenantId) {
            return ['ok' => false, 'error' => 'Utilisateur sans tenant.'];
        }

        $product = Product::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($q) use ($needle) {
                $q->where('sku', $needle)
                  ->orWhere('name', 'like', '%'.$needle.'%');
            })
            ->orderByRaw('CASE WHEN sku = ? THEN 0 WHEN name = ? THEN 1 ELSE 2 END', [$needle, $needle])
            ->first();

        if (! $product) {
            return [
                'ok'    => false,
                'error' => "Produit introuvable pour : « {$needle} ». Vérifie le nom ou le SKU.",
            ];
        }

        $newQty = $product->stock_quantity + $quantity;

        if ($newQty < 0) {
            return [
                'ok'    => false,
                'error' => "Stock insuffisant. Actuel: {$product->stock_quantity}, demandé: ".abs($quantity),
            ];
        }

        DB::transaction(function () use ($product, $newQty) {
            $product->stock_quantity = $newQty;
            $product->save();
        });

        return [
            'ok'              => true,
            'product_id'      => $product->id,
            'product_name'    => $product->name,
            'sku'             => $product->sku,
            'previous_stock'  => $product->stock_quantity - $quantity,
            'movement'        => $quantity,
            'new_stock'       => $newQty,
            'reason'          => $reason,
            'message'         => $quantity > 0
                ? "+{$quantity} {$product->name} ajoutés. Nouveau stock : {$newQty}."
                : abs($quantity)." {$product->name} retirés. Nouveau stock : {$newQty}.",
        ];
    }
}
