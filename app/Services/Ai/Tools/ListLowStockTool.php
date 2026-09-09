<?php

namespace App\Services\Ai\Tools;

use App\Models\Product;

/**
 * Lists every product currently below its stock_alert threshold.
 * Used when the user asks things like "quels produits sont en stock faible ?".
 */
class ListLowStockTool implements AiTool
{
    public function name(): string
    {
        return 'list_low_stock';
    }

    public function schema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name'        => $this->name(),
                'description' => "Retourne la liste des produits en stock faible "
                              .  "(stock_quantity <= seuil d'alerte). "
                              .  "À utiliser pour répondre à : 'quels produits sont en rupture / en stock bas / faible ?'",
                'parameters'  => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => [
                            'type'        => 'integer',
                            'description' => "Nombre maximum de produits à retourner (défaut 20).",
                            'default'     => 20,
                        ],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }

    public function execute(array $args): array
    {
        $limit    = max(1, min(50, (int) ($args['limit'] ?? 20)));
        $tenantId = (int) auth()->user()?->tenant_id;

        if (! $tenantId) {
            return ['ok' => false, 'error' => 'Utilisateur sans tenant.'];
        }

        // Cover both finished products and raw materials — exclude services
        // (no stock to track). is_active intentionally not filtered, see ListMaterialsTool.
        $products = Product::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('type', ['product', 'material'])
            ->whereColumn('stock_quantity', '<=', 'stock_alert')
            ->orderByRaw('(stock_quantity - stock_alert) ASC')
            ->limit($limit)
            ->get(['id', 'name', 'sku', 'type', 'stock_quantity', 'stock_alert', 'unit']);

        if ($products->isEmpty()) {
            return [
                'ok'      => true,
                'count'   => 0,
                'items'   => [],
                'message' => "Aucun produit n'est en stock faible. Tout va bien ✅",
            ];
        }

        $items = $products->map(fn ($p) => [
            'name'        => $p->name,
            'sku'         => $p->sku,
            'type'        => $p->type,
            'stock'       => $p->stock_quantity,
            'alert_level' => $p->stock_alert,
            'unit'        => $p->unit,
            'gap'         => $p->stock_quantity - $p->stock_alert,
        ])->values()->all();

        $summary = $products
            ->map(fn ($p) => sprintf(
                '%s%s (%g/%g %s)',
                $p->name,
                $p->type === 'material' ? ' [matière]' : '',
                $p->stock_quantity,
                $p->stock_alert,
                $p->unit
            ))
            ->take(5)
            ->implode(', ');

        return [
            'ok'      => true,
            'count'   => $products->count(),
            'items'   => $items,
            'message' => "{$products->count()} produit(s) en stock faible : {$summary}"
                       . ($products->count() > 5 ? '…' : '.'),
        ];
    }
}
