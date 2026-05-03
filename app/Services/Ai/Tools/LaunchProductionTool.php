<?php

namespace App\Services\Ai\Tools;

use App\Models\Bom;
use App\Models\Production;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Creates a Production Order (OF) from an existing recipe (BOM).
 * Optionally starts it immediately if `start = true`.
 */
class LaunchProductionTool implements AiTool
{
    public function name(): string
    {
        return 'launch_production';
    }

    public function schema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name'        => $this->name(),
                'description' => "Crée un Ordre de Fabrication (OF) à partir d'une recette existante. "
                              .  "À utiliser pour : 'lance une production de N de X', 'fabrique N X', 'crée un OF de N X'.",
                'parameters'  => [
                    'type' => 'object',
                    'properties' => [
                        'recipe' => [
                            'type'        => 'string',
                            'description' => 'Nom de la recette ou nom du produit à fabriquer.',
                        ],
                        'quantity' => [
                            'type'        => 'number',
                            'description' => 'Quantité à produire (en unités du produit fini, > 0).',
                        ],
                        'batch_number' => [
                            'type'        => 'string',
                            'description' => "Numéro de lot personnalisé (optionnel — auto-généré sinon).",
                        ],
                        'start' => [
                            'type'        => 'boolean',
                            'description' => "Lancer immédiatement la production (status in_progress). Défaut false (status pending).",
                            'default'     => false,
                        ],
                    ],
                    'required' => ['recipe', 'quantity'],
                ],
            ],
        ];
    }

    public function execute(array $args): array
    {
        $recipeNeedle = trim((string) ($args['recipe'] ?? ''));
        $quantity     = (float) ($args['quantity'] ?? 0);
        $batchNumber  = trim((string) ($args['batch_number'] ?? ''));
        $startNow     = (bool) ($args['start'] ?? false);

        if ($recipeNeedle === '' || $quantity <= 0) {
            return ['ok' => false, 'error' => 'recipe et quantity (>0) sont requis.'];
        }

        $tenantId = (int) auth()->user()?->tenant_id;
        if (! $tenantId) {
            return ['ok' => false, 'error' => 'Utilisateur sans tenant.'];
        }

        // Resolve the BOM
        $bom = Bom::query()
            ->with('product:id,name,unit')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where(function ($q) use ($recipeNeedle) {
                $q->where('name', 'like', "%{$recipeNeedle}%")
                  ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$recipeNeedle}%"));
            })
            ->first();

        if (! $bom) {
            return [
                'ok'    => false,
                'error' => "Aucune recette active trouvée pour « {$recipeNeedle} ».",
            ];
        }

        $production = DB::transaction(function () use ($bom, $quantity, $batchNumber, $startNow, $tenantId) {
            $production = Production::create([
                'tenant_id'        => $tenantId,
                'product_id'       => $bom->product_id,
                'bom_id'           => $bom->id,
                'user_id'          => Auth::id(),
                'reference'        => Production::generateReference($tenantId),
                'batch_number'     => $batchNumber !== '' ? $batchNumber : ('BATCH-' . date('YmdHis')),
                'planned_quantity' => $quantity,
                'status'           => $startNow ? 'in_progress' : 'pending',
                'started_at'       => $startNow ? now() : null,
            ]);

            return $production;
        });

        $statusLabel = $startNow ? 'en cours' : 'planifié';

        return [
            'ok'              => true,
            'production_id'   => $production->id,
            'reference'       => $production->reference,
            'batch_number'    => $production->batch_number,
            'product_name'    => $bom->product->name,
            'recipe_name'     => $bom->name ?: $bom->product->name,
            'planned_qty'     => (float) $quantity,
            'unit'            => $bom->product->unit ?? 'u',
            'status'          => $production->status,
            'message'         => sprintf(
                "OF %s créé pour %g %s de « %s » (lot %s) — statut : %s.",
                $production->reference,
                $quantity,
                $bom->product->unit ?? 'u',
                $bom->product->name,
                $production->batch_number,
                $statusLabel
            ),
        ];
    }
}
