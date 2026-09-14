<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Concerns\ScopesByCountry;
use App\Http\Controllers\Controller;
use App\Models\CatalogProduct;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Gestion du catalogue de produits partagé (réservé aux admins plateforme).
 * Un admin pays ne voit/gère que le catalogue de son pays.
 * Le super admin voit tout et peut filtrer/forcer un pays.
 */
class CatalogProductController extends Controller
{
    use ScopesByCountry;

    /** Pays imposé pour un admin pays, sinon celui fourni (super admin). */
    private function resolveCountry(Request $request, bool $required = false): ?string
    {
        $user = $request->user();
        if ($this->isCountryAdmin($user) && $user->country_code) {
            return $user->country_code;
        }
        $country = $request->get('country_code') ?: $request->get('country');
        if ($required) {
            abort_if(!$country, 422, 'Le pays est obligatoire.');
        }
        return $country ? strtoupper($country) : null;
    }

    public function index(Request $request): JsonResponse
    {
        $query = CatalogProduct::query()->latest();

        if ($country = $this->resolveCountry($request)) {
            $query->where('country_code', $country);
        }

        if ($search = $request->get('search')) {
            $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('barcode', 'like', "%{$search}%")
                ->orWhere('brand', 'like', "%{$search}%"));
        }

        $items = $query->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data'    => [
                'products' => collect($items->items())->map(fn ($p) => $this->payload($p)),
                'meta'     => [
                    'total'        => $items->total(),
                    'per_page'     => $items->perPage(),
                    'current_page' => $items->currentPage(),
                    'last_page'    => $items->lastPage(),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $country = $this->resolveCountry($request, required: true);

        $data = $request->validate([
            'barcode'      => 'required|string|max:50',
            'name'         => 'required|string|max:255',
            'brand'        => 'nullable|string|max:255',
            'category'     => ['nullable', Rule::in(array_keys(Product::categories()))],
            'default_unit' => ['nullable', Rule::in(Product::units())],
            'image'        => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
        ]);

        $barcode = preg_replace('/\D/', '', $data['barcode']);

        // Unicité par pays
        $exists = CatalogProduct::where('barcode', $barcode)
            ->where('country_code', $country)
            ->exists();
        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => "Ce code-barres existe déjà dans le catalogue de {$country}.",
            ], 422);
        }

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('catalog', 'public');
        }

        $product = CatalogProduct::create([
            ...$data,
            'barcode'      => $barcode,
            'country_code' => $country,
            'created_by'   => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Produit ajouté au catalogue.',
            'data'    => ['product' => $this->payload($product)],
        ], 201);
    }

    public function update(Request $request, CatalogProduct $catalogProduct): JsonResponse
    {
        $this->assertSameCountry($request, $catalogProduct);

        $data = $request->validate([
            'name'         => 'sometimes|string|max:255',
            'brand'        => 'nullable|string|max:255',
            'category'     => ['nullable', Rule::in(array_keys(Product::categories()))],
            'default_unit' => ['nullable', Rule::in(Product::units())],
            'image'        => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
        ]);

        if ($request->hasFile('image')) {
            if ($catalogProduct->image) {
                Storage::disk('public')->delete($catalogProduct->image);
            }
            $data['image'] = $request->file('image')->store('catalog', 'public');
        } else {
            unset($data['image']);
        }

        $catalogProduct->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Produit du catalogue mis à jour.',
            'data'    => ['product' => $this->payload($catalogProduct->fresh())],
        ]);
    }

    public function destroy(Request $request, CatalogProduct $catalogProduct): JsonResponse
    {
        $this->assertSameCountry($request, $catalogProduct);

        if ($catalogProduct->image) {
            Storage::disk('public')->delete($catalogProduct->image);
        }
        $catalogProduct->delete();

        return response()->json(['success' => true, 'message' => 'Produit retiré du catalogue.']);
    }

    private function assertSameCountry(Request $request, CatalogProduct $product): void
    {
        $user = $request->user();
        if ($this->isCountryAdmin($user)) {
            abort_if($product->country_code !== $user->country_code, 403, 'Ce produit n\'appartient pas à votre pays.');
        }
    }

    private function payload(CatalogProduct $p): array
    {
        return [
            'id'           => $p->id,
            'barcode'      => $p->barcode,
            'country_code' => $p->country_code,
            'name'         => $p->name,
            'brand'        => $p->brand,
            'category'     => $p->category,
            'default_unit' => $p->default_unit,
            'image'        => $p->image
                ? \App\Http\Resources\Api\V1\ProductResource::resolveStorageUrl($p->image)
                : null,
            'created_at'   => $p->created_at->toIso8601String(),
        ];
    }
}
