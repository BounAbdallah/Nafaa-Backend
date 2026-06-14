<?php

namespace App\Http\Controllers\Api\V1\Products;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Recherche d'informations produit à partir d'un code-barres (EAN/UPC).
 * Source : Open Food Facts — base communautaire gratuite et sans clé.
 * Le commerçant complète ensuite prix et quantité manuellement.
 */
class ProductLookupController extends Controller
{
    /**
     * GET /products/lookup-barcode?code=3017620422003
     * Retourne { found, name, brand, category, image_url } ou found=false.
     */
    public function byBarcode(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|max:50',
        ]);

        $code = preg_replace('/\D/', '', $request->get('code'));

        if (strlen($code) < 6) {
            return response()->json([
                'success' => true,
                'data'    => ['found' => false, 'reason' => 'code_invalide'],
            ]);
        }

        try {
            $response = Http::timeout(8)
                ->withHeaders(['User-Agent' => 'QiwamERP/1.0 (contact@qiwam.app)'])
                ->get("https://world.openfoodfacts.org/api/v2/product/{$code}.json", [
                    'fields' => 'product_name,product_name_fr,brands,categories_tags_fr,image_front_url,quantity',
                ]);

            if (! $response->ok()) {
                return response()->json([
                    'success' => true,
                    'data'    => ['found' => false, 'reason' => 'service_indisponible'],
                ]);
            }

            $json = $response->json();

            if (($json['status'] ?? 0) !== 1 || empty($json['product'])) {
                return response()->json([
                    'success' => true,
                    'data'    => ['found' => false, 'reason' => 'produit_inconnu'],
                ]);
            }

            $p     = $json['product'];
            $name  = $p['product_name_fr'] ?? $p['product_name'] ?? null;
            $brand = $p['brands'] ?? null;

            // Nom enrichi de la marque si pertinent
            $fullName = trim($name ?? '');
            if ($brand && $fullName && stripos($fullName, $brand) === false) {
                $fullName = $fullName . ' — ' . explode(',', $brand)[0];
            }

            return response()->json([
                'success' => true,
                'data'    => [
                    'found'     => ! empty($fullName),
                    'name'      => $fullName ?: null,
                    'brand'     => $brand ? explode(',', $brand)[0] : null,
                    'category'  => $this->mapCategory($p['categories_tags_fr'] ?? []),
                    'image_url' => $p['image_front_url'] ?? null,
                    'barcode'   => $code,
                ],
            ]);
        } catch (\Exception $e) {
            Log::warning('Lookup code-barres échoué : ' . $e->getMessage());

            return response()->json([
                'success' => true,
                'data'    => ['found' => false, 'reason' => 'erreur_reseau'],
            ]);
        }
    }

    /**
     * Devine la catégorie interne Qiwam à partir des tags Open Food Facts.
     * Open Food Facts étant alimentaire, on tombe presque toujours sur "alimentaire".
     */
    private function mapCategory(array $tags): ?string
    {
        // Tout produit Open Food Facts est par nature alimentaire
        if (! empty($tags)) {
            return 'alimentaire';
        }

        return null;
    }
}
