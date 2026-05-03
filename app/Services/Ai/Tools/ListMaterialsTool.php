<?php

namespace App\Services\Ai\Tools;

use App\Models\Product;

/**
 * Lists raw materials (products with type = 'material') used as ingredients in BOMs.
 * Distinct from list_products which returns finished products & services.
 */
class ListMaterialsTool implements AiTool
{
    public function name(): string
    {
        return 'list_materials';
    }

    public function schema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name'        => $this->name(),
                'description' => "Liste les matières premières (produits de type material). "
                              .  "À utiliser pour : 'liste mes matières premières', 'mes ingrédients', "
                              .  "'quelles matières ai-je en stock ?'. "
                              .  "NE PAS utiliser pour les produits finis (utiliser list_products à la place).",
                'parameters'  => [
                    'type' => 'object',
                    'properties' => [
                        'search' => [
                            'type'        => 'string',
                            'description' => 'Filtre par nom partiel (optionnel).',
                        ],
                        'low_stock_only' => [
                            'type'        => 'boolean',
                            'description' => 'Ne retourner que les matières en stock faible.',
                            'default'     => false,
                        ],
                        'limit' => [
                            'type'        => 'integer',
                            'description' => 'Nombre maximum (défaut 20).',
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
        $search       = trim((string) ($args['search'] ?? ''));
        $lowOnly      = (bool) ($args['low_stock_only'] ?? false);
        $limit        = max(1, min(50, (int) ($args['limit'] ?? 20)));
        $tenantId     = (int) auth()->user()?->tenant_id;

        if (! $tenantId) {
            return ['ok' => false, 'error' => 'Utilisateur sans tenant.'];
        }

        $query = Product::query()
            ->where('tenant_id', $tenantId)
            ->where('type', 'material')
            ->where('is_active', true);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        if ($lowOnly) {
            $query->whereColumn('stock_quantity', '<=', 'stock_alert');
        }

        $total     = (clone $query)->count();
        $materials = $query->orderBy('name')->limit($limit)->get([
            'id', 'name', 'sku', 'stock_quantity', 'stock_alert', 'unit', 'cost_price',
        ]);

        if ($materials->isEmpty()) {
            return [
                'ok'      => true,
                'count'   => 0,
                'total'   => 0,
                'items'   => [],
                'message' => $lowOnly
                    ? "Aucune matière première en stock faible. ✅"
                    : ($search !== ''
                        ? "Aucune matière première trouvée pour « {$search} »."
                        : "Aucune matière première enregistrée."),
            ];
        }

        $items = $materials->map(fn ($m) => [
            'name'        => $m->name,
            'sku'         => $m->sku,
            'stock'       => $m->stock_quantity,
            'alert_level' => $m->stock_alert,
            'unit'        => $m->unit,
            'cost_price'  => $m->cost_price,
            'is_low'      => $m->stock_quantity <= $m->stock_alert,
        ])->values()->all();

        $summary = $materials->take(5)
            ->map(fn ($m) => sprintf('%s (%g %s)', $m->name, $m->stock_quantity, $m->unit))
            ->implode(', ');

        return [
            'ok'      => true,
            'count'   => $materials->count(),
            'total'   => $total,
            'items'   => $items,
            'message' => "{$total} matière(s) première(s)" . ($lowOnly ? ' en stock faible' : '') . " : {$summary}"
                       . ($total > $materials->count() ? "… (sur {$total})" : '.'),
        ];
    }
}
