<?php

namespace App\Services\Ai\Tools;

use App\Models\Bom;

/**
 * Lists all active recipes (BOMs) of the tenant.
 */
class ListBomsTool implements AiTool
{
    public function name(): string
    {
        return 'list_boms';
    }

    public function schema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name'        => $this->name(),
                'description' => "Liste les recettes / nomenclatures (BOMs) du tenant. "
                              .  "À utiliser pour : 'liste mes recettes', 'quelles BOMs ai-je ?', 'mes recettes actives'.",
                'parameters'  => [
                    'type' => 'object',
                    'properties' => [
                        'search' => [
                            'type'        => 'string',
                            'description' => "Filtre partiel sur le nom de la recette ou du produit (optionnel).",
                        ],
                        'limit' => [
                            'type'        => 'integer',
                            'description' => "Nombre maximum (défaut 20).",
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
        $search   = trim((string) ($args['search'] ?? ''));
        $limit    = max(1, min(50, (int) ($args['limit'] ?? 20)));
        $tenantId = (int) auth()->user()?->tenant_id;

        if (! $tenantId) {
            return ['ok' => false, 'error' => 'Utilisateur sans tenant.'];
        }

        $query = Bom::query()
            ->with(['product:id,name,sku,unit', 'items'])
            ->where('tenant_id', $tenantId)
            ->where('is_active', true);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%"));
            });
        }

        $boms = $query->orderBy('id', 'desc')->limit($limit)->get();

        if ($boms->isEmpty()) {
            return [
                'ok'      => true,
                'count'   => 0,
                'items'   => [],
                'message' => $search !== ''
                    ? "Aucune recette trouvée pour « {$search} »."
                    : "Aucune recette enregistrée pour le moment.",
            ];
        }

        $items = $boms->map(fn ($b) => [
            'id'              => $b->id,
            'name'            => $b->name ?: ($b->product->name ?? '—'),
            'product_name'    => $b->product->name ?? null,
            'product_sku'     => $b->product->sku ?? null,
            'output_quantity' => $b->quantity,
            'output_unit'     => $b->product->unit ?? 'unité',
            'ingredients'     => $b->items->count(),
            'waste_percent'   => $b->waste_percentage,
        ])->values()->all();

        $summary = $boms->take(5)
            ->map(fn ($b) => sprintf(
                '%s (→ %g %s, %d ingrédient%s)',
                $b->name ?: ($b->product->name ?? 'sans nom'),
                $b->quantity,
                $b->product->unit ?? 'u',
                $b->items->count(),
                $b->items->count() > 1 ? 's' : ''
            ))
            ->implode(' · ');

        return [
            'ok'      => true,
            'count'   => $boms->count(),
            'items'   => $items,
            'message' => "{$boms->count()} recette(s) : {$summary}"
                       . ($boms->count() > 5 ? '…' : '.'),
        ];
    }
}
