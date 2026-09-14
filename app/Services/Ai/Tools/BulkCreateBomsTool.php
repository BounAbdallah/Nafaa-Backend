<?php

namespace App\Services\Ai\Tools;

use App\Models\Bom;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Crée plusieurs recettes (BOM) d'un coup depuis un import CSV/XLSX.
 *
 * Format d'entrée attendu (produit de BomSpreadsheetParser) :
 *   [
 *     'boms' => [
 *       [
 *         'product_name'    => 'Thiéboudienne',
 *         'bom_quantity'    => 1,
 *         'waste_percentage'=> 5,
 *         'ingredients'     => [
 *           ['name' => 'Riz',     'quantity' => 2,   'unit' => 'kg'],
 *           ['name' => 'Poisson', 'quantity' => 1.5, 'unit' => 'kg'],
 *         ],
 *       ],
 *       ...
 *     ]
 *   ]
 *
 * Comportement :
 *   - Si le produit fini n'existe pas → le crée (type=product)
 *   - Si une matière première n'existe pas → la crée (type=material)
 *   - Si une recette existe déjà pour ce produit → la met à jour
 *   - Renvoie un rapport détaillé par recette
 */
class BulkCreateBomsTool implements AiTool
{
    private const VALID_UNITS = [
        'pièce', 'kg', 'g', 'litre', 'cl', 'ml', 'm', 'cm', 'm²', 'm³',
        'boîte', 'carton', 'sac', 'heure', 'jour', 'forfait',
    ];

    public function name(): string
    {
        return 'bulk_create_boms';
    }

    public function schema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name'        => $this->name(),
                'description' => "Importe plusieurs recettes (BOM) d'un coup depuis un tableau/CSV. "
                              .  "Utiliser quand l'utilisateur fournit une liste de recettes avec ingrédients.",
                'parameters'  => [
                    'type' => 'object',
                    'properties' => [
                        'boms' => [
                            'type'  => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'product_name'     => ['type' => 'string'],
                                    'bom_quantity'     => ['type' => 'number'],
                                    'waste_percentage' => ['type' => 'number'],
                                    'ingredients'      => [
                                        'type'  => 'array',
                                        'items' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'name'     => ['type' => 'string'],
                                                'quantity' => ['type' => 'number'],
                                                'unit'     => ['type' => 'string'],
                                            ],
                                            'required' => ['name', 'quantity'],
                                        ],
                                    ],
                                ],
                                'required' => ['product_name', 'ingredients'],
                            ],
                        ],
                    ],
                    'required' => ['boms'],
                ],
            ],
        ];
    }

    public function execute(array $args): array
    {
        $tenantId = (int) auth()->user()?->tenant_id;
        if (! $tenantId) {
            return ['ok' => false, 'error' => 'Utilisateur sans tenant.'];
        }

        $boms = $args['boms'] ?? [];
        if (empty($boms)) {
            return ['ok' => false, 'error' => 'Aucune recette fournie.'];
        }

        $created  = [];
        $updated  = [];
        $skipped  = [];
        $autoCreatedProducts     = [];
        $autoCreatedMaterials    = [];

        foreach ($boms as $idx => $bomData) {
            $productName = trim((string) ($bomData['product_name'] ?? ''));
            if ($productName === '') {
                $skipped[] = ['row' => $idx + 1, 'reason' => 'Nom de produit vide'];
                continue;
            }

            $ingredients = $bomData['ingredients'] ?? [];
            if (empty($ingredients)) {
                $skipped[] = ['row' => $idx + 1, 'product' => $productName, 'reason' => 'Aucun ingrédient'];
                continue;
            }

            try {
                $result = DB::transaction(function () use (
                    $bomData, $productName, $ingredients, $tenantId,
                    &$autoCreatedProducts, &$autoCreatedMaterials
                ) {
                    // ── 1. Trouver ou créer le produit fini ────────────────
                    $product = Product::where('tenant_id', $tenantId)
                        ->whereRaw('LOWER(name) = ?', [mb_strtolower($productName)])
                        ->first();

                    if (! $product) {
                        $product = Product::create([
                            'tenant_id'     => $tenantId,
                            'name'          => $productName,
                            'sku'           => $this->generateSku($productName, 'product', $tenantId),
                            'type'          => 'product',
                            'unit'          => 'pièce',
                            'cost_price'    => 0,
                            'selling_price' => 0,
                            'stock_quantity'=> 0,
                            'stock_alert'   => 5,
                            'is_active'     => true,
                        ]);
                        $autoCreatedProducts[] = $productName;
                    }

                    // ── 2. Résoudre chaque ingrédient ──────────────────────
                    $resolvedItems = [];
                    foreach ($ingredients as $ing) {
                        $ingName = trim((string) ($ing['name'] ?? ''));
                        if ($ingName === '') continue;

                        $ingQty  = max(0.001, (float) ($ing['quantity'] ?? 1));
                        $ingUnit = $this->normalizeUnit((string) ($ing['unit'] ?? 'pièce'));

                        $material = Product::where('tenant_id', $tenantId)
                            ->whereRaw('LOWER(name) = ?', [mb_strtolower($ingName)])
                            ->first();

                        if (! $material) {
                            $material = Product::create([
                                'tenant_id'     => $tenantId,
                                'name'          => $ingName,
                                'sku'           => $this->generateSku($ingName, 'material', $tenantId),
                                'type'          => 'material',
                                'unit'          => $ingUnit,
                                'cost_price'    => 0,
                                'selling_price' => 0,
                                'stock_quantity'=> 0,
                                'stock_alert'   => 5,
                                'is_active'     => true,
                            ]);
                            $autoCreatedMaterials[] = $ingName;
                        }

                        $resolvedItems[] = [
                            'ingredient_id' => $material->id,
                            'quantity'      => $ingQty,
                        ];
                    }

                    if (empty($resolvedItems)) {
                        throw new \RuntimeException('Aucun ingrédient valide.');
                    }

                    // ── 3. Créer ou mettre à jour la BOM ──────────────────
                    $existingBom = Bom::where('tenant_id', $tenantId)
                        ->where('product_id', $product->id)
                        ->first();

                    $bomQty  = max(0.001, (float) ($bomData['bom_quantity'] ?? 1));
                    $waste   = max(0, min(100, (float) ($bomData['waste_percentage'] ?? 0)));

                    if ($existingBom) {
                        $existingBom->update([
                            'quantity'         => $bomQty,
                            'waste_percentage' => $waste,
                        ]);
                        $existingBom->items()->delete();
                        foreach ($resolvedItems as $item) {
                            $existingBom->items()->create($item);
                        }
                        return ['action' => 'updated', 'name' => $productName, 'ingredients' => count($resolvedItems)];
                    } else {
                        $newBom = Bom::create([
                            'tenant_id'        => $tenantId,
                            'product_id'       => $product->id,
                            'name'             => null,
                            'quantity'         => $bomQty,
                            'waste_percentage' => $waste,
                            'is_active'        => true,
                        ]);
                        foreach ($resolvedItems as $item) {
                            $newBom->items()->create($item);
                        }
                        return ['action' => 'created', 'name' => $productName, 'ingredients' => count($resolvedItems)];
                    }
                });

                if ($result['action'] === 'created') {
                    $created[] = $result;
                } else {
                    $updated[] = $result;
                }
            } catch (\Throwable $e) {
                $skipped[] = ['product' => $productName, 'reason' => $e->getMessage()];
            }
        }

        // ── Message de synthèse ────────────────────────────────────────────
        $parts = [];
        if (count($created)) $parts[] = count($created) . ' recette(s) créée(s)';
        if (count($updated)) $parts[] = count($updated) . ' recette(s) mise(s) à jour';
        if (count($skipped)) $parts[] = count($skipped) . ' ignorée(s)';
        if (count($autoCreatedProducts))  $parts[] = count($autoCreatedProducts)  . ' produit(s) créé(s) automatiquement';
        if (count($autoCreatedMaterials)) $parts[] = count($autoCreatedMaterials) . ' matière(s) créée(s) automatiquement';

        $ok = count($created) > 0 || count($updated) > 0;

        $message = $ok
            ? 'Import recettes terminé : ' . implode(', ', $parts) . '.'
            : 'Aucune recette importée. ' . (count($skipped) ? "Raison : {$skipped[0]['reason']}." : '');

        if ($ok && count($autoCreatedProducts)) {
            $message .= "\n⚠️ Produits créés sans prix : " . implode(', ', $autoCreatedProducts) . '. Pensez à renseigner leur prix de vente.';
        }
        if ($ok && count($autoCreatedMaterials)) {
            $message .= "\n⚠️ Matières créées sans coût : " . implode(', ', $autoCreatedMaterials) . '. Pensez à renseigner leur prix d\'achat.';
        }

        return [
            'ok'                      => $ok,
            'created'                 => $created,
            'updated'                 => $updated,
            'skipped'                 => $skipped,
            'auto_created_products'   => $autoCreatedProducts,
            'auto_created_materials'  => $autoCreatedMaterials,
            'message'                 => $message,
        ];
    }

    private function generateSku(string $name, string $type, int $tenantId): string
    {
        $core = collect(preg_split('/\s+/', $name))
            ->filter()
            ->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 3)))
            ->take(3)
            ->implode('-') ?: 'ITM';

        $prefix = match ($type) {
            'material' => 'MAT',
            'service'  => 'SVC',
            default    => 'PRD',
        };

        for ($i = 0; $i < 5; $i++) {
            $sku = "{$prefix}-{$core}-" . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            if (! Product::where('tenant_id', $tenantId)->where('sku', $sku)->exists()) {
                return $sku;
            }
        }
        return "{$prefix}-{$core}-" . substr((string) time(), -6);
    }

    private function normalizeUnit(string $u): string
    {
        $u = trim(mb_strtolower($u));
        return match ($u) {
            'l', 'litres', 'liters' => 'litre',
            'pieces', 'pce', 'pcs', 'p', 'u' => 'pièce',
            'grammes', 'gramme', 'gr' => 'g',
            'kilos', 'kilo' => 'kg',
            '' => 'pièce',
            default => (in_array($u, self::VALID_UNITS, true) ? $u : 'pièce'),
        };
    }
}
