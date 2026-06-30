<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ExpenseCategoryController extends Controller
{
    /** Retourne les catégories : systèmes + personnalisées du tenant. */
    public function index(): JsonResponse
    {
        $tenantId = Auth::user()->tenant_id;

        $system = collect(Expense::categories())
            ->map(fn($l, $v) => ['value' => $v, 'label' => $l, 'custom' => false])
            ->values();

        $custom = ExpenseCategory::where('tenant_id', $tenantId)
            ->orderBy('label')
            ->get()
            ->map(fn($c) => ['value' => $c->slug, 'label' => $c->label, 'custom' => true, 'id' => $c->id]);

        return response()->json([
            'success' => true,
            'data'    => ['categories' => $system->concat($custom)->values()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(Auth::user()->canDo('expenses', 'create'), 403, 'Permission refusée.');

        $data = $request->validate([
            'label' => 'required|string|max:100',
        ]);

        $tenantId = Auth::user()->tenant_id;
        $slug     = Str::slug($data['label']);

        // Vérifier unicité parmi les catégories système + custom
        $systemSlugs = array_keys(Expense::categories());
        abort_if(in_array($slug, $systemSlugs), 422, 'Cette catégorie existe déjà.');

        $existing = ExpenseCategory::where('tenant_id', $tenantId)->where('slug', $slug)->exists();
        abort_if($existing, 422, 'Une catégorie avec ce nom existe déjà.');

        $category = ExpenseCategory::create([
            'tenant_id' => $tenantId,
            'slug'      => $slug,
            'label'     => trim($data['label']),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Catégorie créée.',
            'data'    => ['category' => ['value' => $category->slug, 'label' => $category->label, 'custom' => true, 'id' => $category->id]],
        ], 201);
    }

    public function destroy(ExpenseCategory $expenseCategory): JsonResponse
    {
        abort_unless(Auth::user()->canDo('expenses', 'delete'), 403, 'Permission refusée.');
        abort_if($expenseCategory->tenant_id !== Auth::user()->tenant_id, 403);

        $expenseCategory->delete();

        return response()->json(['success' => true, 'message' => 'Catégorie supprimée.']);
    }
}
