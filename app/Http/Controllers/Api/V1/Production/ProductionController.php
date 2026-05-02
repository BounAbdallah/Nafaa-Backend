<?php

namespace App\Http\Controllers\Api\V1\Production;

use App\Http\Controllers\Controller;
use App\Models\Production;
use App\Models\Bom;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class ProductionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $productions = Production::where('tenant_id', $tenantId)
            ->with(['product:id,name,unit', 'bom:id,name', 'user:id,name'])
            ->latest()
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $productions
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'bom_id' => 'required|exists:boms,id',
            'planned_quantity' => 'required|numeric|min:0.001',
            'batch_number' => 'nullable|string|max:100',
        ]);

        $production = Production::create([
            'tenant_id' => $tenantId,
            'product_id' => $validated['product_id'],
            'bom_id' => $validated['bom_id'],
            'user_id' => Auth::id(),
            'reference' => Production::generateReference($tenantId),
            'batch_number' => $validated['batch_number'] ?? ('BATCH-' . date('YmdHis')),
            'planned_quantity' => $validated['planned_quantity'],
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Ordre de production créé',
            'data' => $production->load(['product', 'bom'])
        ], 201);
    }

    public function start(Request $request, Production $production): JsonResponse
    {
        $this->authorizeTenant($request, $production);
        
        if ($production->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Impossible de lancer cette production.'], 422);
        }

        $production->update([
            'status' => 'in_progress',
            'started_at' => Carbon::now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Production lancée',
            'data' => $production
        ]);
    }

    public function complete(Request $request, Production $production): JsonResponse
    {
        $this->authorizeTenant($request, $production);
        
        if ($production->status !== 'in_progress') {
            return response()->json(['success' => false, 'message' => 'La production doit être en cours pour être clôturée.'], 422);
        }

        $validated = $request->validate([
            'actual_quantity' => 'required|numeric|min:0',
            'waste_quantity' => 'nullable|numeric|min:0',
            'expiry_date' => 'nullable|date|after:today',
        ]);

        $result = DB::transaction(function () use ($production, $validated) {
            $bom = $production->bom->load('items.ingredient');
            $totalCost = 0;
            
            // 1. Déduction des ingrédients
            // Ratio de production (si on produit plus ou moins que prévu dans le BOM de base)
            $ratio = $production->planned_quantity / $bom->quantity;
            
            foreach ($bom->items as $item) {
                $requiredQty = $item->quantity * $ratio;
                // On applique aussi le pourcentage de perte du BOM global sur chaque ingrédient?
                // Généralement le waste_percentage du BOM est sur le produit fini, 
                // mais si on perd 10% du produit fini, on a quand même consommé les ingrédients.
                $consumedQty = $requiredQty * (1 + ($bom->waste_percentage / 100));
                
                $ingredient = $item->ingredient;
                $ingredient->decrement('stock_quantity', $consumedQty);
                
                $totalCost += $consumedQty * $ingredient->cost_price;
            }

            // 2. Ajout du produit fini
            $product = $production->product;
            $product->increment('stock_quantity', $validated['actual_quantity']);
            
            // Mise à jour du prix de revient moyen du produit fini
            $newCostPrice = $totalCost / ($validated['actual_quantity'] > 0 ? $validated['actual_quantity'] : 1);
            $product->update(['cost_price' => $newCostPrice]);

            // 3. Mise à jour de la production
            $production->update([
                'status' => 'completed',
                'actual_quantity' => $validated['actual_quantity'],
                'waste_quantity' => $validated['waste_quantity'] ?? 0,
                'expiry_date' => $validated['expiry_date'] ?? null,
                'total_cost' => $totalCost,
                'completed_at' => Carbon::now(),
            ]);

            return $production;
        });

        return response()->json([
            'success' => true,
            'message' => 'Production terminée et stocks mis à jour',
            'data' => $result
        ]);
    }

    public function cancel(Request $request, Production $production): JsonResponse
    {
        $this->authorizeTenant($request, $production);
        
        if (in_array($production->status, ['completed', 'cancelled'])) {
            return response()->json(['success' => false, 'message' => 'Impossible d\'annuler une production terminée ou déjà annulée.'], 422);
        }

        $production->update(['status' => 'cancelled']);

        return response()->json([
            'success' => true,
            'message' => 'Production annulée'
        ]);
    }

    public function checkAvailability(Request $request): JsonResponse
    {
        $request->validate([
            'bom_id' => 'required|exists:boms,id',
            'quantity' => 'required|numeric|min:0.001',
        ]);

        $bom = Bom::with('items.ingredient')->findOrFail($request->bom_id);
        $this->authorizeTenant($request, $bom);

        $ratio = $request->quantity / $bom->quantity;
        $availability = [];
        $canProduce = true;
        $maxPossible = PHP_FLOAT_MAX;

        foreach ($bom->items as $item) {
            $required = $item->quantity * $ratio * (1 + ($bom->waste_percentage / 100));
            $available = $item->ingredient->stock_quantity;
            
            $possibleWithThis = $item->ingredient->quantity > 0 
                ? ($available / ($item->quantity * (1 + ($bom->waste_percentage / 100)))) * $bom->quantity
                : 0;
            
            if ($item->quantity > 0) {
                 $maxPossible = min($maxPossible, ($available / ($item->quantity * (1 + ($bom->waste_percentage / 100)))) * $bom->quantity);
            }

            $availability[] = [
                'ingredient_id' => $item->ingredient_id,
                'name' => $item->ingredient->name,
                'required' => $required,
                'available' => $available,
                'sufficient' => $available >= $required,
                'unit' => $item->ingredient->unit
            ];

            if ($available < $required) $canProduce = false;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'can_produce' => $canProduce,
                'max_possible' => floor($maxPossible * 1000) / 1000,
                'details' => $availability
            ]
        ]);
    }

    private function authorizeTenant(Request $request, $model): void
    {
        abort_if($model->tenant_id !== $request->user()->tenant_id, 403);
    }
}
