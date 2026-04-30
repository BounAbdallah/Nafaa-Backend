<?php

namespace App\Http\Controllers\Api\V1\Suppliers;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PurchaseOrderResource;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PurchaseOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $query    = PurchaseOrder::where('tenant_id', $tenantId)
                        ->with(['supplier', 'items', 'user']);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) =>
                $q->where('reference', 'like', "%$s%")
                  ->orWhereHas('supplier', fn($sq) => $sq->where('name', 'like', "%$s%"))
            );
        }
        if ($request->filled('status'))      $query->where('status', $request->status);
        if ($request->filled('supplier_id')) $query->where('supplier_id', $request->supplier_id);

        $orders = $query->orderByDesc('order_date')->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data'    => [
                'orders' => PurchaseOrderResource::collection($orders->items()),
                'meta'   => [
                    'total'        => $orders->total(),
                    'per_page'     => $orders->perPage(),
                    'current_page' => $orders->currentPage(),
                    'last_page'    => $orders->lastPage(),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'supplier_id'          => 'required|integer',
            'order_date'           => 'required|date',
            'expected_date'        => 'nullable|date|after_or_equal:order_date',
            'notes'                => 'nullable|string',
            'items'                => 'required|array|min:1',
            'items.*.description'  => 'required|string|max:255',
            'items.*.unit'         => 'required|string',
            'items.*.quantity'     => 'required|numeric|min:0.001',
            'items.*.unit_price'   => 'required|numeric|min:0',
            'items.*.product_id'   => 'nullable|integer',
        ]);

        $tenantId = $request->user()->tenant_id;

        // Vérifier que le fournisseur appartient au tenant
        $supplier = Supplier::where('id', $data['supplier_id'])
                            ->where('tenant_id', $tenantId)
                            ->firstOrFail();

        $order = DB::transaction(function () use ($data, $tenantId, $request, $supplier) {
            $order = PurchaseOrder::create([
                'tenant_id'     => $tenantId,
                'supplier_id'   => $supplier->id,
                'user_id'       => $request->user()->id,
                'reference'     => PurchaseOrder::generateReference($tenantId),
                'status'        => 'draft',
                'order_date'    => $data['order_date'],
                'expected_date' => $data['expected_date'] ?? null,
                'notes'         => $data['notes'] ?? null,
                'total_amount'  => 0,
            ]);

            foreach ($data['items'] as $item) {
                $order->items()->create([
                    'product_id'  => $item['product_id'] ?? null,
                    'description' => $item['description'],
                    'unit'        => $item['unit'],
                    'quantity'    => $item['quantity'],
                    'unit_price'  => $item['unit_price'],
                ]);
            }

            $order->recalculateTotal();
            return $order;
        });

        return response()->json([
            'success' => true,
            'message' => 'Bon de commande créé.',
            'data'    => ['order' => new PurchaseOrderResource($order->load(['supplier', 'items.product', 'user']))],
        ], 201);
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorizeTenant($request, $purchaseOrder);
        return response()->json([
            'success' => true,
            'data'    => ['order' => new PurchaseOrderResource($purchaseOrder->load(['supplier', 'items.product', 'user']))],
        ]);
    }

    public function updateStatus(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorizeTenant($request, $purchaseOrder);

        $data = $request->validate([
            'status'         => ['required', Rule::in(array_keys(PurchaseOrder::$statuses))],
            'received_date'  => 'nullable|date',
            'selling_prices' => 'nullable|array', // [{item_id, selling_price}]
        ]);

        abort_if($purchaseOrder->status === 'cancelled', 422, 'Une commande annulée ne peut pas être modifiée.');
        abort_if($purchaseOrder->status === 'received',  422, 'Une commande déjà reçue ne peut pas être modifiée.');

        $oldStatus = $purchaseOrder->status;

        DB::transaction(function () use ($data, $purchaseOrder, $oldStatus) {
            $purchaseOrder->update([
                'status'        => $data['status'],
                'received_date' => $data['received_date'] ?? $purchaseOrder->received_date,
            ]);

            // À la réception totale : mettre à jour le stock des produits liés
            if ($data['status'] === 'received' && $oldStatus !== 'received') {
                $sellingPrices = collect($data['selling_prices'] ?? [])->keyBy('item_id');

                foreach ($purchaseOrder->items()->get() as $item) {
                    $product = $item->product;
                    $newPrice = isset($sellingPrices[$item->id]) ? $sellingPrices[$item->id]['selling_price'] : null;

                    // Si le produit n'existe pas, on le crée à la volée
                    if (!$product && $item->description) {
                        $product = Product::create([
                            'tenant_id'      => $purchaseOrder->tenant_id,
                            'name'           => $item->description,
                            'sku'            => 'AUTO-' . strtoupper(substr(uniqid(), -6)),
                            'type'           => 'product',
                            'category'       => 'autre',
                            'unit'           => $item->unit ?? 'pièce',
                            'cost_price'     => $item->unit_price,
                            'selling_price'  => $newPrice ?? ($item->unit_price * 1.2),
                            'stock_quantity' => 0,
                            'is_active'      => true,
                        ]);
                        $item->update(['product_id' => $product->id]);
                    }

                    if ($product) {
                        $product->increment('stock_quantity', $item->quantity);
                        
                        $updateData = ['cost_price' => $item->unit_price];
                        if ($newPrice) {
                            $updateData['selling_price'] = $newPrice;
                        }
                        $product->update($updateData);

                        $item->update(['received_quantity' => $item->quantity]);
                    }
                }
                
                // Mettre à jour les stats du fournisseur
                $purchaseOrder->supplier->increment('orders_count');
                $purchaseOrder->supplier->increment('total_ordered', $purchaseOrder->total_amount);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Statut mis à jour.',
            'data'    => ['order' => new PurchaseOrderResource($purchaseOrder->fresh()->load(['supplier', 'items']))],
        ]);
    }

    public function destroy(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorizeTenant($request, $purchaseOrder);
        abort_if($purchaseOrder->status === 'received', 422, 'Impossible de supprimer une commande reçue.');
        $purchaseOrder->delete();
        return response()->json(['success' => true, 'message' => 'Bon de commande supprimé.']);
    }

    public function meta(Request $request): JsonResponse
    {
        $tenantId  = $request->user()->tenant_id;
        $suppliers = Supplier::where('tenant_id', $tenantId)
                             ->where('is_active', true)
                             ->orderBy('name')
                             ->get(['id', 'name']);

        $products  = Product::where('tenant_id', $tenantId)
                            ->where('is_active', true)
                            ->orderBy('name')
                            ->get(['id', 'name', 'unit']);

        return response()->json([
            'success' => true,
            'data'    => [
                'suppliers' => $suppliers,
                'products'  => $products,
                'statuses'  => collect(PurchaseOrder::$statuses)->map(fn($l, $v) => ['value' => $v, 'label' => $l])->values(),
                'units'     => Product::units(),
            ],
        ]);
    }

    private function authorizeTenant(Request $request, PurchaseOrder $purchaseOrder): void
    {
        abort_if($purchaseOrder->tenant_id !== $request->user()->tenant_id, 403);
    }
}
