<?php

namespace App\Services\Ai\Tools;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bulk-creates multiple products in a single transaction.
 * Designed for chat tables, CSV imports, and any "create N products at once" flow.
 *
 * Behaviour:
 *   - Skips duplicates (case-insensitive name match within tenant)
 *   - Falls back to sensible defaults for missing fields
 *   - Returns a per-row report so the user knows exactly what happened
 */
class BulkCreateProductsTool implements AiTool
{
    private const VALID_UNITS = [
        'pièce', 'kg', 'g', 'litre', 'cl', 'ml', 'm', 'cm', 'm²', 'm³',
        'boîte', 'carton', 'sac', 'heure', 'jour', 'forfait',
    ];

    public function name(): string
    {
        return 'bulk_create_products';
    }

    public function schema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name'        => $this->name(),
                'description' => "Crée plusieurs produits/matières/services d'un coup. "
                              .  "À utiliser quand l'utilisateur fournit un tableau ou une liste structurée. "
                              .  "Chaque ligne doit avoir au minimum un nom. "
                              .  "Tu DOIS extraire chaque ligne du tableau utilisateur et la passer dans `items`.",
                'parameters'  => [
                    'type' => 'object',
                    'properties' => [
                        'default_type' => [
                            'type'        => 'string',
                            'enum'        => ['product', 'service', 'material'],
                            'description' => "Type appliqué aux items qui ne le précisent pas (défaut 'material' pour un tableau de matières premières).",
                        ],
                        'items' => [
                            'type'  => 'array',
                            'minItems' => 1,
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'name'           => ['type' => 'string'],
                                    'category'       => ['type' => 'string', 'description' => 'Catégorie libre (ex: Légumes, Condiments).'],
                                    'unit'           => ['type' => 'string', 'enum' => self::VALID_UNITS],
                                    'cost_price'     => ['type' => 'number', 'description' => "Prix d'achat en FCFA (>= 0)."],
                                    'selling_price'  => ['type' => 'number', 'description' => "Prix de vente (optionnel, défaut 0 pour matière)."],
                                    'stock_quantity' => ['type' => 'number', 'description' => 'Stock initial. Sera arrondi à l’entier le plus proche.'],
                                    'stock_alert'    => ['type' => 'integer'],
                                    'description'   => ['type' => 'string'],
                                    'type'          => ['type' => 'string', 'enum' => ['product', 'service', 'material']],
                                ],
                                'required' => ['name'],
                            ],
                        ],
                    ],
                    'required' => ['items'],
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

        $items = $args['items'] ?? [];
        if (! is_array($items) || empty($items)) {
            return ['ok' => false, 'error' => 'Aucun item fourni.'];
        }

        $defaultType = (string) ($args['default_type'] ?? 'material');
        if (! in_array($defaultType, ['product', 'service', 'material'], true)) {
            $defaultType = 'material';
        }

        // Pre-load existing names once to detect duplicates without N queries
        $existingNames = Product::query()
            ->where('tenant_id', $tenantId)
            ->pluck('name')
            ->map(fn ($n) => mb_strtolower($n))
            ->all();

        $created = [];
        $skipped = [];
        $rounded = [];

        DB::transaction(function () use (
            $items, $tenantId, $defaultType, &$created, &$skipped, &$rounded, &$existingNames
        ) {
            foreach ($items as $idx => $item) {
                $name = trim((string) ($item['name'] ?? ''));
                if ($name === '') {
                    $skipped[] = ['row' => $idx + 1, 'reason' => 'Nom vide'];
                    continue;
                }

                $key = mb_strtolower($name);
                if (in_array($key, $existingNames, true)) {
                    $skipped[] = ['row' => $idx + 1, 'name' => $name, 'reason' => 'Déjà existant'];
                    continue;
                }

                $type = (string) ($item['type'] ?? $defaultType);
                if (! in_array($type, ['product', 'service', 'material'], true)) {
                    $type = $defaultType;
                }

                $unit = (string) ($item['unit'] ?? 'pièce');
                if (! in_array($unit, self::VALID_UNITS, true)) {
                    $unit = 'pièce';
                }

                $rawStock = (float) ($item['stock_quantity'] ?? 0);
                $stockInt = (int) round($rawStock);
                if (abs($rawStock - $stockInt) > 0.001) {
                    $rounded[] = [
                        'name'     => $name,
                        'original' => $rawStock,
                        'rounded'  => $stockInt,
                        'unit'     => $unit,
                    ];
                }

                $product = Product::create([
                    'tenant_id'      => $tenantId,
                    'name'           => $name,
                    'sku'            => $this->generateSku($name, $type, $tenantId),
                    'type'           => $type,
                    'category'       => trim((string) ($item['category'] ?? '')) ?: null,
                    'unit'           => $unit,
                    'cost_price'     => (float) ($item['cost_price'] ?? 0),
                    'selling_price'  => (float) ($item['selling_price'] ?? 0),
                    'stock_quantity' => $type === 'service' ? 0 : max(0, $stockInt),
                    'stock_alert'    => (int) ($item['stock_alert'] ?? 5),
                    'description'    => trim((string) ($item['description'] ?? '')) ?: null,
                    'is_active'      => true,
                ]);

                $existingNames[] = $key;
                $created[] = [
                    'name'  => $product->name,
                    'sku'   => $product->sku,
                    'type'  => $product->type,
                    'stock' => $product->stock_quantity,
                    'unit'  => $product->unit,
                ];
            }
        });

        $msgParts = [];
        if (count($created)) {
            $msgParts[] = count($created).' créé(s)';
        }
        if (count($skipped)) {
            $msgParts[] = count($skipped).' ignoré(s)';
        }
        if (count($rounded)) {
            $msgParts[] = count($rounded).' arrondi(s) à l\'entier';
        }

        return [
            'ok'       => count($created) > 0,
            'created'  => $created,
            'skipped'  => $skipped,
            'rounded'  => $rounded,
            'count'    => count($created),
            'message'  => count($created) === 0
                ? 'Aucun nouveau produit créé. ' . (count($skipped) ? "{$skipped[0]['reason']} pour la première ligne." : '')
                : 'Import terminé : ' . implode(', ', $msgParts) . '.',
        ];
    }

    private function generateSku(string $name, string $type, int $tenantId): string
    {
        $core = collect(preg_split('/\s+/', $name))
            ->filter()
            ->map(fn ($w) => mb_strtoupper(mb_substr(Str::ascii($w), 0, 3)))
            ->take(3)
            ->implode('-') ?: 'PRD';

        $prefix = match ($type) {
            'material' => 'MAT',
            'service'  => 'SVC',
            default    => 'PRD',
        };

        for ($i = 0; $i < 5; $i++) {
            $sku = "{$prefix}-{$core}-".str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            if (! Product::where('tenant_id', $tenantId)->where('sku', $sku)->exists()) {
                return $sku;
            }
        }

        return "{$prefix}-{$core}-".substr((string) time(), -6);
    }
}
