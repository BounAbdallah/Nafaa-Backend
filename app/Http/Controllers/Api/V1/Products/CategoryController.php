<?php

namespace App\Http\Controllers\Api\V1\Products;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $categories = Category::where('tenant_id', $tenantId)
            ->withCount('products')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => ['categories' => $categories],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'name'        => [
                'required', 'string', 'max:255',
                Rule::unique('categories')->where('tenant_id', $tenantId),
            ],
            'description' => 'nullable|string',
            'color'       => 'nullable|string|max:50',
            'icon'        => 'nullable|string|max:50',
            'is_active'   => 'boolean',
        ]);

        $category = Category::create([
            ...$data,
            'tenant_id' => $tenantId,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Catégorie créée.',
            'data'    => ['category' => $category],
        ], 201);
    }

    public function show(Request $request, Category $category): JsonResponse
    {
        $this->authorizeTenant($request, $category);
        return response()->json(['success' => true, 'data' => ['category' => $category]]);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $this->authorizeTenant($request, $category);
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'name'        => [
                'sometimes', 'string', 'max:255',
                Rule::unique('categories')->where('tenant_id', $tenantId)->ignore($category->id),
            ],
            'description' => 'nullable|string',
            'color'       => 'nullable|string|max:50',
            'icon'        => 'nullable|string|max:50',
            'is_active'   => 'boolean',
        ]);

        $category->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Catégorie mise à jour.',
            'data'    => ['category' => $category->fresh()],
        ]);
    }

    public function destroy(Request $request, Category $category): JsonResponse
    {
        $this->authorizeTenant($request, $category);
        
        if ($category->products()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer une catégorie contenant des produits.',
            ], 422);
        }

        $category->delete();
        return response()->json(['success' => true, 'message' => 'Catégorie supprimée.']);
    }

    private function authorizeTenant(Request $request, Category $category): void
    {
        abort_if($category->tenant_id !== $request->user()->tenant_id, 403);
    }
}
