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
        if ($request->filled('type'))     $query->where('type', $request->type);
        if ($request->filled('category')) {
            $query->where(fn($q) => $q->where('category', $request->category)->orWhere('category_id', $request->category));
        }
        if ($request->filled('active'))   $query->where('is_active', $request->boolean('active'));
        if ($request->boolean('low_stock')) {
            $query->whereRaw('stock_quantity <= stock_alert')->where('type', 'product');
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
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'name'           => 'required|string|max:255',
            'sku'            => ['nullable', 'string', 'max:100',
                                  Rule::unique('products')->where('tenant_id', $request->user()->tenant_id)],
            'description'    => 'nullable|string',
            'type'           => ['required', Rule::in(['product', 'service'])],
            'category'       => ['nullable', Rule::in(array_keys(Product::categories()))],
            'category_id'    => [
                'nullable', 'integer',
                Rule::exists('categories', 'id')->where('tenant_id', $tenantId),
            ],
            'unit'           => ['required', Rule::in(Product::units())],
            'selling_price'  => 'required|numeric|min:0',
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
            'cost_price'     => $data['cost_price'] ?? 0,
            'stock_quantity' => $data['type'] === 'service' ? 0 : ($data['stock_quantity'] ?? 0),
            'stock_alert'    => $data['stock_alert'] ?? 5,
            'is_active'      => $data['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Produit créé avec succès.',
            'data'    => ['product' => new ProductResource($product)],
        ], 201);
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        $this->authorizeTenant($request, $product);
        return response()->json(['success' => true, 'data' => ['product' => new ProductResource($product)]]);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $this->authorizeTenant($request, $product);
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'name'           => 'sometimes|string|max:255',
            'sku'            => ['nullable', 'string', 'max:100',
                                  Rule::unique('products')->where('tenant_id', $request->user()->tenant_id)->ignore($product->id)],
            'description'    => 'nullable|string',
            'type'           => ['sometimes', Rule::in(['product', 'service'])],
            'category'       => ['nullable', Rule::in(array_keys(Product::categories()))],
            'category_id'    => [
                'nullable', 'integer',
                Rule::exists('categories', 'id')->where('tenant_id', $tenantId),
            ],
            'unit'           => ['sometimes', Rule::in(Product::units())],
            'selling_price'  => 'sometimes|numeric|min:0',
            'cost_price'     => 'nullable|numeric|min:0',
            'stock_quantity' => 'nullable|integer|min:0',
            'stock_alert'    => 'nullable|integer|min:0',
            'image'          => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'is_active'      => 'boolean',
        ]);

        if ($request->hasFile('image')) {
            // Supprimer l'ancienne image
            if ($product->image) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($product->image);
            }
            $path = $request->file('image')->store('products', 'public');
            $data['image'] = $path;
        }

        $product->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Produit mis à jour.',
            'data'    => ['product' => new ProductResource($product->fresh())],
        ]);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->authorizeTenant($request, $product);
        $product->delete();

        return response()->json(['success' => true, 'message' => 'Produit supprimé.']);
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
            ->selectRaw('DATE_FORMAT(orders.created_at, "%Y-%m") as month, SUM(order_items.subtotal) as total')
            ->groupBy('month')
            ->get()
            ->pluck('total', 'month');

        // Expenses (Dépenses / Achats)
        $expensesData = \Illuminate\Support\Facades\DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_order_items.purchase_order_id', '=', 'purchase_orders.id')
            ->where('purchase_order_items.product_id', $product->id)
            ->where('purchase_orders.status', '!=', 'cancelled')
            ->selectRaw('DATE_FORMAT(purchase_orders.created_at, "%Y-%m") as month, SUM(purchase_order_items.quantity * purchase_order_items.unit_price) as total')
            ->groupBy('month')
            ->get()
            ->pluck('total', 'month');

        $chartData = $months->map(function ($m) use ($revenueData, $expensesData) {
            $monthKey = substr($m['date_start'], 0, 7); // YYYY-MM
            return [
                'name'     => $m['label'],
                'Revenus'  => (float)($revenueData[$monthKey] ?? 0),
                'Dépenses' => (float)($expensesData[$monthKey] ?? 0),
            ];
        });

        $totalRevenue = \Illuminate\Support\Facades\DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->where('order_items.product_id', $product->id)
            ->where('orders.status', '!=', 'cancelled')
            ->sum('order_items.subtotal');

        $totalExpenses = \Illuminate\Support\Facades\DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_order_items.purchase_order_id', '=', 'purchase_orders.id')
            ->where('purchase_order_items.product_id', $product->id)
            ->where('purchase_orders.status', '!=', 'cancelled')
            ->sum(\Illuminate\Support\Facades\DB::raw('purchase_order_items.quantity * purchase_order_items.unit_price'));

        return response()->json([
            'success' => true,
            'data'    => [
                'total_revenue'  => (float)$totalRevenue,
                'total_expenses' => (float)$totalExpenses,
                'profit'         => (float)$totalRevenue - (float)$totalExpenses,
                'chart_data'     => $chartData,
            ],
        ]);
    }

    private function authorizeTenant(Request $request, Product $product): void
    {
        abort_if($product->tenant_id !== $request->user()->tenant_id, 403);
    }
}
