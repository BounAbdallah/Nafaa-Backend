<?php

namespace App\Http\Controllers\Api\V1\Products;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $query = Product::where('tenant_id', $tenantId)->with('category');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q->where('name', 'like', "%$s%")->orWhere('sku', 'like', "%$s%"));
        }
        if ($request->filled('type'))         $query->where('type', $request->type);
        if ($request->filled('exclude_type')) $query->where('type', '!=', $request->exclude_type);
        if ($request->filled('category')) {
            $query->where(fn($q) => $q->where('category', $request->category)->orWhere('category_id', $request->category));
        }
        if ($request->filled('active'))   $query->where('is_active', $request->boolean('active'));
        if ($request->boolean('low_stock')) {
            $query->whereRaw('stock_quantity <= stock_alert')->whereIn('type', ['product', 'material']);
        }

        $sortMap = ['name' => 'name', 'price' => 'selling_price', 'stock' => 'stock_quantity', 'date' => 'created_at'];
        $sort  = $sortMap[$request->get('sort', 'date')] ?? 'created_at';
        $order = $request->get('order', 'desc') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $order);

        $products = $query->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data'    => [
                'products' => ProductResource::collection($products->items()),
                'meta'     => [
                    'total'        => $products->total(),
                    'per_page'     => $products->perPage(),
                    'current_page' => $products->currentPage(),
                    'last_page'    => $products->lastPage(),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->canDo('products', 'create'), 403, 'Permission refusée.');
        $tenantId = $request->user()->tenant_id;

        // ── Limite de produits du pack ──
        $tenant      = $request->user()->tenant;
        $maxProducts = (int) ($tenant->getPlanLimits()['products'] ?? -1);
        if ($maxProducts !== -1 && Product::where('tenant_id', $tenantId)->count() >= $maxProducts) {
            return response()->json([
                'success' => false,
                'message' => "Limite atteinte : votre pack autorise {$maxProducts} produits. Passez à un pack supérieur pour en ajouter davantage.",
                'code'    => 'PLAN_LIMIT_PRODUCTS',
            ], 422);
        }

        $data = $request->validate([
            'name'           => 'required|string|max:255',
            'sku'            => ['nullable', 'string', 'max:100',
                                  Rule::unique('products')->where('tenant_id', $request->user()->tenant_id)->whereNull('deleted_at')],
            'description'    => 'nullable|string',
            'type'           => ['required', Rule::in(['product', 'service', 'material'])],
            'category'       => ['nullable', Rule::in(array_keys(Product::categories()))],
            'category_id'    => [
                'nullable', 'integer',
                Rule::exists('categories', 'id')->where('tenant_id', $tenantId),
            ],
            'unit'           => ['required', Rule::in(Product::units())],
            'selling_price'  => 'required|numeric|min:0',
            'min_price'      => 'nullable|numeric|min:0',
            'cost_price'     => 'nullable|numeric|min:0',
            'stock_quantity' => 'nullable|integer|min:0',
            'stock_alert'    => 'nullable|integer|min:0',
            'image'          => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'is_active'      => 'boolean',
        ]);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('products', 'public');
            $data['image'] = $path;
        }

        $product = Product::create([
            ...$data,
            'tenant_id'      => $request->user()->tenant_id,
            'min_price'      => $data['min_price'] ?? 0,
            'cost_price'     => $data['cost_price'] ?? 0,
            'stock_quantity' => $data['type'] === 'service' ? 0 : ($data['stock_quantity'] ?? 0),
            'stock_alert'    => $data['stock_alert'] ?? 5,
            'is_active'      => $data['is_active'] ?? true,
        ]);

        $product->loadMissing('category');

        return response()->json([
            'success' => true,
            'message' => 'Produit créé avec succès.',
            'data'    => ['product' => new ProductResource($product)],
        ], 201);
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        $this->authorizeTenant($request, $product);
        $product->loadMissing('category');

        // Last purchase price from supplier orders
        $lastPurchase = \Illuminate\Support\Facades\DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_order_items.purchase_order_id', '=', 'purchase_orders.id')
            ->where('purchase_order_items.product_id', $product->id)
            ->where('purchase_orders.tenant_id', $product->tenant_id)
            ->whereNotIn('purchase_orders.status', ['cancelled'])
            ->whereNull('purchase_orders.deleted_at')
            ->orderByDesc('purchase_orders.created_at')
            ->select('purchase_order_items.unit_price', 'purchase_orders.created_at')
            ->first();

        // Last production unit cost (for products made via production/BOM)
        $lastProduction = \Illuminate\Support\Facades\DB::table('productions')
            ->where('product_id', $product->id)
            ->where('tenant_id', $product->tenant_id)
            ->where('status', 'completed')
            ->whereRaw('actual_quantity > 0')
            ->orderByDesc('completed_at')
            ->select('total_cost', 'actual_quantity', 'completed_at')
            ->first();

        $hasBom = \App\Models\Bom::where('product_id', $product->id)
            ->where('tenant_id', $product->tenant_id)
            ->where('is_active', true)
            ->exists();

        $pricing = [
            'last_purchase_price'      => $lastPurchase ? (float) $lastPurchase->unit_price : null,
            'last_purchase_date'       => $lastPurchase ? $lastPurchase->created_at : null,
            'last_production_unit_cost'=> $lastProduction
                ? round((float) $lastProduction->total_cost / (float) $lastProduction->actual_quantity, 0)
                : null,
            'last_production_date'     => $lastProduction ? $lastProduction->completed_at : null,
            'has_bom'                  => $hasBom,
        ];

        return response()->json([
            'success' => true,
            'data'    => ['product' => array_merge((new ProductResource($product))->resolve($request), ['pricing' => $pricing])],
        ]);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $this->authorizeTenant($request, $product);
        abort_unless($request->user()->canDo('products', 'edit'), 403, 'Permission refusée.');
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'name'           => 'sometimes|string|max:255',
            'sku'            => ['nullable', 'string', 'max:100',
                                  Rule::unique('products')->where('tenant_id', $request->user()->tenant_id)->whereNull('deleted_at')->ignore($product->id)],
            'description'    => 'nullable|string',
            'type'           => ['sometimes', Rule::in(['product', 'service', 'material'])],
            'category'       => ['nullable', Rule::in(array_keys(Product::categories()))],
            'category_id'    => [
                'nullable', 'integer',
                Rule::exists('categories', 'id')->where('tenant_id', $tenantId),
            ],
            'unit'           => ['sometimes', Rule::in(Product::units())],
            'selling_price'  => 'sometimes|numeric|min:0',
            'min_price'      => 'nullable|numeric|min:0',
            'cost_price'     => 'nullable|numeric|min:0',
            'stock_quantity' => 'nullable|integer|min:0',
            'stock_alert'    => 'nullable|integer|min:0',
            'image'          => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'is_active'      => 'boolean',
        ]);

        if ($request->hasFile('image')) {
            // Supprimer l'ancienne image si elle existe et est un chemin valide
            if ($product->image && !str_starts_with($product->image, 'http') && $product->image !== '0') {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($product->image);
            }
            $path = $request->file('image')->store('products', 'public');
            $data['image'] = $path;
        } else {
            // Aucun nouveau fichier → ne pas toucher à l'image existante
            unset($data['image']);
        }

        $product->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Produit mis à jour.',
            'data'    => ['product' => new ProductResource($product->fresh()->load('category'))],
        ]);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->authorizeTenant($request, $product);
        abort_unless($request->user()->canDo('products', 'delete'), 403, 'Permission refusée.');
        $product->delete();

        return response()->json(['success' => true, 'message' => 'Produit déplacé dans la corbeille.']);
    }

    /**
     * Liste les produits dans la corbeille (soft-deleted) du tenant.
     */
    public function trashed(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $query = Product::onlyTrashed()->where('tenant_id', $tenantId)->with('category')->latest('deleted_at');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn ($q) => $q->where('name', 'like', "%$s%")->orWhere('sku', 'like', "%$s%"));
        }

        $products = $query->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data'    => [
                'products' => ProductResource::collection($products->items()),
                'meta'     => [
                    'total'        => $products->total(),
                    'per_page'     => $products->perPage(),
                    'current_page' => $products->currentPage(),
                    'last_page'    => $products->lastPage(),
                ],
            ],
        ]);
    }

    /**
     * Restaure un produit depuis la corbeille.
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        abort_unless($request->user()->canDo('products', 'delete'), 403, 'Permission refusée.');

        $product = Product::onlyTrashed()
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($id);

        $product->restore();

        return response()->json(['success' => true, 'message' => 'Produit restauré.']);
    }

    /**
     * Supprime définitivement un produit de la corbeille.
     */
    public function forceDelete(Request $request, int $id): JsonResponse
    {
        abort_unless($request->user()->canDo('products', 'delete'), 403, 'Permission refusée.');

        $product = Product::onlyTrashed()
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($id);

        // Supprimer l'image associée si elle existe
        if ($product->image && !str_starts_with($product->image, 'http') && $product->image !== '0') {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($product->image);
        }

        $product->forceDelete();

        return response()->json(['success' => true, 'message' => 'Produit supprimé définitivement.']);
    }
    public function meta(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        return response()->json([
            'success' => true,
            'data'    => [
                'static_categories' => collect(Product::categories())->map(fn($l, $v) => ['value' => $v, 'label' => $l])->values(),
                'dynamic_categories' => \App\Models\Category::where('tenant_id', $tenantId)->where('is_active', true)->get(),
                'units'      => Product::units(),
            ],
        ]);
    }

    public function stats(Request $request, Product $product): JsonResponse
    {
        $this->authorizeTenant($request, $product);

        $months = collect(range(5, 0))->map(function ($i) {
            $date = now()->startOfMonth()->subMonths($i);
            return [
                'date_start' => $date->format('Y-m-d'),
                'date_end'   => $date->copy()->endOfMonth()->format('Y-m-d'),
                'label'      => $date->translatedFormat('M'), // e.g., 'Jan', 'Fév'
                'revenue'    => 0,
                'expenses'   => 0,
            ];
        });

        // Revenue (Ventes)
        $revenueData = \Illuminate\Support\Facades\DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->where('order_items.product_id', $product->id)
            ->where('orders.status', '!=', 'cancelled')
            ->whereNull('orders.deleted_at')
            ->selectRaw('DATE_FORMAT(orders.created_at, "%Y-%m") as month, SUM(order_items.subtotal) as total')
            ->groupBy('month')
            ->get()
            ->pluck('total', 'month');

        // Cost of Goods Sold per month = qty_sold × cost_price
        $cogsData = \Illuminate\Support\Facades\DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->where('order_items.product_id', $product->id)
            ->where('orders.status', '!=', 'cancelled')
            ->whereNull('orders.deleted_at')
            ->selectRaw('DATE_FORMAT(orders.created_at, "%Y-%m") as month, SUM(order_items.quantity) as qty')
            ->groupBy('month')
            ->get()
            ->mapWithKeys(fn($row) => [$row->month => round((float)$row->qty * $product->cost_price, 2)]);

        $chartData = $months->map(function ($m) use ($revenueData, $cogsData) {
            $monthKey = substr($m['date_start'], 0, 7);
            return [
                'name'     => $m['label'],
                'Revenus'  => (float)($revenueData[$monthKey] ?? 0),
                'Coût'     => (float)($cogsData[$monthKey] ?? 0),
            ];
        });

        // Totals
        $totalRevenue = \Illuminate\Support\Facades\DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->where('order_items.product_id', $product->id)
            ->where('orders.status', '!=', 'cancelled')
            ->whereNull('orders.deleted_at')
            ->sum('order_items.subtotal');

        $totalQtySold = \Illuminate\Support\Facades\DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->where('order_items.product_id', $product->id)
            ->where('orders.status', '!=', 'cancelled')
            ->whereNull('orders.deleted_at')
            ->sum('order_items.quantity');

        $totalCogs = round((float)$totalQtySold * $product->cost_price, 2);

        // Purchase orders total (for info only)
        $totalPurchases = \Illuminate\Support\Facades\DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_order_items.purchase_order_id', '=', 'purchase_orders.id')
            ->where('purchase_order_items.product_id', $product->id)
            ->where('purchase_orders.status', '!=', 'cancelled')
            ->whereNull('purchase_orders.deleted_at')
            ->sum(\Illuminate\Support\Facades\DB::raw('purchase_order_items.quantity * purchase_order_items.unit_price'));

        return response()->json([
            'success' => true,
            'data'    => [
                'total_revenue'   => (float)$totalRevenue,
                'total_expenses'  => $totalCogs,           // COGS = cost_price × qty sold
                'total_purchases' => (float)$totalPurchases, // BCs fournisseurs (info)
                'total_qty_sold'  => (float)$totalQtySold,
                'profit'          => (float)$totalRevenue - $totalCogs,
                'chart_data'      => $chartData,
            ],
        ]);
    }

    private function authorizeTenant(Request $request, Product $product): void
    {
        abort_if($product->tenant_id !== $request->user()->tenant_id, 403);
    }
}
