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

        $query = Product::where('tenant_id', $tenantId);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q->where('name', 'like', "%$s%")->orWhere('sku', 'like', "%$s%"));
        }
        if ($request->filled('type'))     $query->where('type', $request->type);
        if ($request->filled('category')) $query->where('category', $request->category);
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
        $data = $request->validate([
            'name'           => 'required|string|max:255',
            'sku'            => ['nullable', 'string', 'max:100',
                                  Rule::unique('products')->where('tenant_id', $request->user()->tenant_id)],
            'description'    => 'nullable|string',
            'type'           => ['required', Rule::in(['product', 'service'])],
            'category'       => ['nullable', Rule::in(array_keys(Product::categories()))],
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

        $data = $request->validate([
            'name'           => 'sometimes|string|max:255',
            'sku'            => ['nullable', 'string', 'max:100',
                                  Rule::unique('products')->where('tenant_id', $request->user()->tenant_id)->ignore($product->id)],
            'description'    => 'nullable|string',
            'type'           => ['sometimes', Rule::in(['product', 'service'])],
            'category'       => ['nullable', Rule::in(array_keys(Product::categories()))],
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

    public function meta(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'categories' => collect(Product::categories())->map(fn($l, $v) => ['value' => $v, 'label' => $l])->values(),
                'units'      => Product::units(),
            ],
        ]);
    }

    private function authorizeTenant(Request $request, Product $product): void
    {
        abort_if($product->tenant_id !== $request->user()->tenant_id, 403);
    }
}
