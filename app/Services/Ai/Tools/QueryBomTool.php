<?php

namespace App\Services\Ai\Tools;

use App\Models\Bom;

/**
 * Returns the full breakdown of a recipe:
 * ingredients, individual costs, total cost of revenue, theoretical margin.
 */
class QueryBomTool implements AiTool
{
    public function name(): string
    {
        return 'query_bom';
    }

    public function schema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name'        => $this->name(),
                'description' => "Détail complet d'une recette : ingrédients, quantités, coûts unitaires, "
                              .  "coût de revient total et marge brute. À utiliser pour : "
                              .  "'détails de la recette X', 'combien coûte X à produire ?', 'rentabilité de la recette Y ?'",
                'parameters'  => [
                    'type' => 'object',
                    'properties' => [
                        'recipe' => [
                            'type'        => 'string',
                            'description' => 'Nom de la recette ou nom du produit fini.',
                        ],
                    ],
                    'required' => ['recipe'],
                ],
            ],
        ];
    }

    public function execute(array $args): array
    {
        $needle   = trim((string) ($args['recipe'] ?? ''));
        $tenantId = (int) auth()->user()?->tenant_id;

        if ($needle === '' || ! $tenantId) {
            return ['ok' => false, 'error' => 'Paramètre manquant.'];
        }

        $bom = Bom::query()
            ->with(['product:id,name,sku,unit,selling_price', 'items.ingredient:id,name,sku,unit,cost_price'])
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where(function ($q) use ($needle) {
                $q->where('name', 'like', "%{$needle}%")
                  ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$needle}%"));
            })
            ->first();

        if (! $bom) {
            return [
                'ok'    => false,
                'error' => "Aucune recette trouvée pour « {$needle} ».",
            ];
        }

        $totalCost = 0.0;
        $items = $bom->items->map(function ($it) use (&$totalCost) {
            $unitCost = (float) ($it->ingredient->cost_price ?? 0);
            $subtotal = $unitCost * (float) $it->quantity;
            $totalCost += $subtotal;

            return [
                'ingredient'    => $it->ingredient->name ?? '—',
                'sku'           => $it->ingredient->sku ?? null,
                'quantity'      => (float) $it->quantity,
                'unit'          => $it->ingredient->unit ?? 'u',
                'unit_cost'     => $unitCost,
                'subtotal_cost' => $subtotal,
            ];
        })->values()->all();

        // Apply waste percentage on top
        $wastePct = (float) $bom->waste_percentage;
        if ($wastePct > 0) {
            $totalCost *= (1 + ($wastePct / 100));
        }

        $sellingPrice = (float) ($bom->product->selling_price ?? 0);
        $outputQty    = (float) $bom->quantity;
        $unitCost     = $outputQty > 0 ? $totalCost / $outputQty : 0;
        $unitMargin   = $sellingPrice - $unitCost;
        $marginPct    = $sellingPrice > 0 ? ($unitMargin / $sellingPrice) * 100 : 0;

        $summaryItems = collect($items)->take(3)
            ->map(fn ($i) => "{$i['ingredient']} ({$i['quantity']} {$i['unit']})")
            ->implode(', ');

        $message = sprintf(
            "Recette « %s » → %g %s. Ingrédients : %s%s. Coût total : %s FCFA (%s FCFA/%s). Marge brute : %.0f%% (%s FCFA/u).",
            $bom->name ?: $bom->product->name,
            $outputQty, $bom->product->unit ?? 'u',
            $summaryItems,
            count($items) > 3 ? '…' : '',
            number_format($totalCost, 0, ',', ' '),
            number_format($unitCost, 0, ',', ' '),
            $bom->product->unit ?? 'u',
            $marginPct,
            number_format($unitMargin, 0, ',', ' ')
        );

        return [
            'ok'              => true,
            'bom_id'          => $bom->id,
            'recipe_name'     => $bom->name ?: $bom->product->name,
            'product_name'    => $bom->product->name ?? null,
            'output_quantity' => $outputQty,
            'output_unit'     => $bom->product->unit ?? 'u',
            'waste_percent'   => $wastePct,
            'items'           => $items,
            'total_cost'      => round($totalCost, 2),
            'unit_cost'       => round($unitCost, 2),
            'selling_price'   => $sellingPrice,
            'unit_margin'     => round($unitMargin, 2),
            'margin_percent'  => round($marginPct, 1),
            'message'         => $message,
        ];
    }
}
