<?php

namespace App\Http\Controllers\Api\V1\Production;

use App\Http\Controllers\Controller;
use App\Models\Bom;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BomController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $boms = Bom::where('tenant_id', $tenantId)
            ->with(['product:id,name,unit,cost_price,selling_price'])
            ->withCount('items')
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $boms
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'name' => 'nullable|string|max:255',
            'quantity' => 'required|numeric|min:0.001',
            'waste_percentage' => 'nullable|numeric|min:0|max:100',
            'items' => 'required|array|min:1',
            'items.*.ingredient_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.001',
        ]);

        $bom = DB::transaction(function () use ($validated, $tenantId) {
            $bom = Bom::create([
                'tenant_id' => $tenantId,
                'product_id' => $validated['product_id'],
                'name' => $validated['name'] ?? null,
                'quantity' => $validated['quantity'],
                'waste_percentage' => $validated['waste_percentage'] ?? 0,
            ]);

            foreach ($validated['items'] as $item) {
                $bom->items()->create($item);
            }

            return $bom->load('items.ingredient');
        });

        return response()->json([
            'success' => true,
            'message' => 'Recette créée avec succès',
            'data' => $bom
        ], 201);
    }

    public function show(Request $request, Bom $bom): JsonResponse
    {
        $this->authorizeTenant($request, $bom);
        
        return response()->json([
            'success' => true,
            'data' => $bom->load(['product', 'items.ingredient'])
        ]);
    }

    public function update(Request $request, Bom $bom): JsonResponse
    {
        $this->authorizeTenant($request, $bom);
        
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'quantity' => 'required|numeric|min:0.001',
            'waste_percentage' => 'nullable|numeric|min:0|max:100',
            'is_active' => 'boolean',
            'items' => 'required|array|min:1',
            'items.*.ingredient_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.001',
        ]);

        DB::transaction(function () use ($validated, $bom) {
            $bom->update([
                'name' => $validated['name'] ?? $bom->name,
                'quantity' => $validated['quantity'],
                'waste_percentage' => $validated['waste_percentage'] ?? 0,
                'is_active' => $validated['is_active'] ?? $bom->is_active,
            ]);

            $bom->items()->delete();
            foreach ($validated['items'] as $item) {
                $bom->items()->create($item);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Recette mise à jour avec succès',
            'data' => $bom->load('items.ingredient')
        ]);
    }

    public function destroy(Request $request, Bom $bom): JsonResponse
    {
        $this->authorizeTenant($request, $bom);
        $bom->delete();

        return response()->json([
            'success' => true,
            'message' => 'Recette supprimée'
        ]);
    }

    public function getMeta(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        
        // Produits finis ou semi-finis (qui peuvent être produits)
        $products = Product::where('tenant_id', $tenantId)
            ->where('type', 'product')
            ->orderBy('name')
            ->get(['id', 'name', 'unit']);

        // Ingrédients (matières premières ou autres produits)
        $ingredients = Product::where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get(['id', 'name', 'unit', 'cost_price', 'stock_quantity']);

        return response()->json([
            'success' => true,
            'data' => [
                'products' => $products,
                'ingredients' => $ingredients
            ]
        ]);
    }

    private function authorizeTenant(Request $request, Bom $bom): void
    {
        abort_if($bom->tenant_id !== $request->user()->tenant_id, 403);
    }
}
