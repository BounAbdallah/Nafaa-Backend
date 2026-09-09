<?php

namespace App\Services\Ai\Tools;

use App\Models\Product;

/**
 * Generic product listing / search tool.
 * Useful for queries like "quels sont mes produits ?" or "trouve-moi les produits 'tissu'".
 */
class ListProductsTool implements AiTool
{
    public function name(): string
    {
        return 'list_products';
    }

    public function schema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name'        => $this->name(),
                'description' => "Liste ou recherche les produits du catalogue. "
                              .  "Utiliser pour : 'liste mes produits', 'trouve les produits X', 'combien de produits ?'",
                'parameters'  => [
                    'type' => 'object',
                    'properties' => [
                        'search' => [
                            'type'        => 'string',
                            'description' => "Filtre par nom partiel ou SKU (optionnel).",
                        ],
                        'limit' => [
                            'type'        => 'integer',
                            'description' => "Nombre maximum de produits (défaut 10).",
                            'default'     => 10,
                        ],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }

    public function execute(array $args): array
    {
        $search   = trim((string) ($args['search'] ?? ''));
        $limit    = max(1, min(50, (int) ($args['limit'] ?? 10)));
        $tenantId = (int) auth()->user()?->tenant_id;

        if (! $tenantId) {
            return ['ok' => false, 'error' => 'Utilisateur sans tenant.'];
        }

        // Default: finished products & services — exclude raw materials (use list_materials for those).
        // We don't filter by is_active so the user sees the same set as in the UI.
        $query = Product::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('type', ['product', 'service']);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        $total    = (clone $query)->count();
        $products = $query
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'sku', 'stock_quantity', 'selling_price', 'unit']);

        if ($products->isEmpty()) {
            return [
                'ok'      => true,
                'count'   => 0,
                'total'   => 0,
                'items'   => [],
                'message' => $search !== ''
                    ? "Aucun produit trouvé pour « {$search} »."
                    : 'Aucun produit dans le catalogue.',
            ];
        }

        $items = $products->map(fn ($p) => [
            'name'          => $p->name,
            'sku'           => $p->sku,
            'stock'         => $p->stock_quantity,
            'selling_price' => $p->selling_price,
            'unit'          => $p->unit,
        ])->values()->all();

        $summary = $products->take(5)->pluck('name')->implode(', ');

        return [
            'ok'      => true,
            'count'   => $products->count(),
            'total'   => $total,
            'items'   => $items,
            'message' => "{$total} produit(s) au total. Affichés : {$summary}"
                       . ($total > $products->count() ? "… (sur {$total})" : '.'),
        ];
    }
}
