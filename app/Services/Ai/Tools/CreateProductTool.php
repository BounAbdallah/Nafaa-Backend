<?php

namespace App\Services\Ai\Tools;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a new product in the catalogue.
 * Smart defaults so the LLM only needs name + selling_price for a quick voice creation.
 */
class CreateProductTool implements AiTool
{
    private const VALID_UNITS = [
        'pièce', 'kg', 'g', 'litre', 'cl', 'ml', 'm', 'cm', 'm²', 'm³',
        'boîte', 'carton', 'sac', 'heure', 'jour', 'forfait',
    ];

    public function name(): string
    {
        return 'create_product';
    }

    public function schema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name'        => $this->name(),
                'description' => "Crée un nouveau produit (ou service) dans le catalogue. "
                              .  "Seuls le nom et le prix de vente sont obligatoires — les autres champs ont des valeurs par défaut.",
                'parameters'  => [
                    'type' => 'object',
                    'properties' => [
                        'name' => [
                            'type'        => 'string',
                            'description' => 'Nom commercial du produit (ex: "Jus de bissap").',
                        ],
                        'selling_price' => [
                            'type'        => 'number',
                            'description' => 'Prix de vente en FCFA (>= 0).',
                        ],
                        'cost_price' => [
                            'type'        => 'number',
                            'description' => 'Prix de revient/coût (optionnel, défaut 0).',
                        ],
                        'unit' => [
                            'type'        => 'string',
                            'enum'        => self::VALID_UNITS,
                            'description' => "Unité de mesure (défaut 'pièce').",
                        ],
                        'type' => [
                            'type'        => 'string',
                            'enum'        => ['product', 'service', 'material'],
                            'description' => "Type d'item (défaut 'product').",
                        ],
                        'stock_quantity' => [
                            'type'        => 'integer',
                            'description' => 'Stock initial (défaut 0).',
                        ],
                        'stock_alert' => [
                            'type'        => 'integer',
                            'description' => "Seuil d'alerte de stock bas (défaut 5).",
                        ],
                        'description' => [
                            'type'        => 'string',
                            'description' => 'Description courte (optionnel).',
                        ],
                    ],
                    'required' => ['name', 'selling_price'],
                ],
            ],
        ];
    }

    public function execute(array $args): array
    {
        $name         = trim((string) ($args['name'] ?? ''));
        $sellingPrice = (float) ($args['selling_price'] ?? 0);

        if ($name === '') {
            return ['ok' => false, 'error' => 'Le nom du produit est requis.'];
        }
        if ($sellingPrice < 0) {
            return ['ok' => false, 'error' => 'Le prix de vente doit être positif.'];
        }

        $tenantId = (int) auth()->user()?->tenant_id;
        if (! $tenantId) {
            return ['ok' => false, 'error' => 'Utilisateur sans tenant.'];
        }

        // Reject duplicate by name (case-insensitive) for the same tenant
        $exists = Product::query()
            ->where('tenant_id', $tenantId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists();

        if ($exists) {
            return [
                'ok'    => false,
                'error' => "Un produit nommé « {$name} » existe déjà. Utilise un autre nom ou modifie l'existant.",
            ];
        }

        $unit = (string) ($args['unit'] ?? 'pièce');
        if (! in_array($unit, self::VALID_UNITS, true)) {
            $unit = 'pièce';
        }

        $type = (string) ($args['type'] ?? 'product');
        if (! in_array($type, ['product', 'service', 'material'], true)) {
            $type = 'product';
        }

        // Auto-generate a deterministic-ish SKU
        $sku = $this->generateSku($name, $tenantId);

        $product = DB::transaction(function () use ($args, $tenantId, $name, $sellingPrice, $unit, $type, $sku) {
            return Product::create([
                'tenant_id'      => $tenantId,
                'name'           => $name,
                'sku'            => $sku,
                'type'           => $type,
                'unit'           => $unit,
                'selling_price'  => $sellingPrice,
                'cost_price'     => (float) ($args['cost_price'] ?? 0),
                'stock_quantity' => $type === 'service' ? 0 : (int) ($args['stock_quantity'] ?? 0),
                'stock_alert'    => (int) ($args['stock_alert'] ?? 5),
                'description'    => (string) ($args['description'] ?? '') ?: null,
                'is_active'      => true,
            ]);
        });

        return [
            'ok'             => true,
            'product_id'     => $product->id,
            'product_name'   => $product->name,
            'sku'            => $product->sku,
            'selling_price'  => $product->selling_price,
            'unit'           => $product->unit,
            'type'           => $product->type,
            'stock_quantity' => $product->stock_quantity,
            'message'        => "Produit « {$product->name} » créé (SKU: {$product->sku}, "
                              . number_format($product->selling_price, 0, ',', ' ')." FCFA / {$product->unit}).",
        ];
    }

    private function generateSku(string $name, int $tenantId): string
    {
        // Take 3 first letters of each word, uppercased
        $prefix = collect(preg_split('/\s+/', $name))
            ->filter()
            ->map(fn ($w) => mb_strtoupper(mb_substr(Str::ascii($w), 0, 3)))
            ->take(3)
            ->implode('-');

        $prefix = $prefix ?: 'PRD';

        // Append random 4-digit suffix, retry if collision
        for ($i = 0; $i < 5; $i++) {
            $sku = $prefix.'-'.str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            $taken = Product::where('tenant_id', $tenantId)->where('sku', $sku)->exists();
            if (! $taken) return $sku;
        }

        // Fallback: timestamp-based
        return $prefix.'-'.substr((string) time(), -6);
    }
}
