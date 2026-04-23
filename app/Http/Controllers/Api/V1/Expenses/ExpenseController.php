<?php

namespace App\Http\Controllers\Api\V1\Expenses;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ExpenseResource;
use App\Models\Expense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $query    = Expense::where('tenant_id', $tenantId)->with('user');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) =>
                $q->where('description', 'like', "%$s%")
                  ->orWhere('category', 'like', "%$s%")
            );
        }
        if ($request->filled('category'))    $query->where('category', $request->category);
        if ($request->filled('month')) {
            [$year, $month] = explode('-', $request->month);
            $query->whereYear('expense_date', $year)->whereMonth('expense_date', $month);
        }

        $expenses = $query->orderByDesc('expense_date')->paginate($request->get('per_page', 20));

        // Calcul du total pour la période affichée
        $totalQuery = Expense::where('tenant_id', $tenantId);
        if ($request->filled('month')) {
            [$year, $month] = explode('-', $request->month);
            $totalQuery->whereYear('expense_date', $year)->whereMonth('expense_date', $month);
        }
        $periodTotal = $totalQuery->sum('amount');

        return response()->json([
            'success' => true,
            'data'    => [
                'expenses'     => ExpenseResource::collection($expenses->items()),
                'period_total' => $periodTotal,
                'meta'         => [
                    'total'        => $expenses->total(),
                    'per_page'     => $expenses->perPage(),
                    'current_page' => $expenses->currentPage(),
                    'last_page'    => $expenses->lastPage(),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category'       => ['required', Rule::in(array_keys(Expense::categories()))],
            'description'    => 'required|string|max:255',
            'amount'         => 'required|numeric|min:0',
            'payment_method' => ['required', Rule::in(array_keys(Expense::paymentMethods()))],
            'expense_date'   => 'required|date',
            'notes'          => 'nullable|string',
        ]);

        $expense = Expense::create([
            ...$data,
            'tenant_id' => $request->user()->tenant_id,
            'user_id'   => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Dépense enregistrée.',
            'data'    => ['expense' => new ExpenseResource($expense->load('user'))],
        ], 201);
    }

    public function show(Request $request, Expense $expense): JsonResponse
    {
        $this->authorizeTenant($request, $expense);
        return response()->json(['success' => true, 'data' => ['expense' => new ExpenseResource($expense->load('user'))]]);
    }

    public function update(Request $request, Expense $expense): JsonResponse
    {
        $this->authorizeTenant($request, $expense);

        $data = $request->validate([
            'category'       => ['sometimes', Rule::in(array_keys(Expense::categories()))],
            'description'    => 'sometimes|string|max:255',
            'amount'         => 'sometimes|numeric|min:0',
            'payment_method' => ['sometimes', Rule::in(array_keys(Expense::paymentMethods()))],
            'expense_date'   => 'sometimes|date',
            'notes'          => 'nullable|string',
        ]);

        $expense->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Dépense mise à jour.',
            'data'    => ['expense' => new ExpenseResource($expense->fresh()->load('user'))],
        ]);
    }

    public function destroy(Request $request, Expense $expense): JsonResponse
    {
        $this->authorizeTenant($request, $expense);
        $expense->delete();
        return response()->json(['success' => true, 'message' => 'Dépense supprimée.']);
    }

    public function meta(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'categories'      => collect(Expense::categories())->map(fn($l, $v) => ['value' => $v, 'label' => $l])->values(),
                'payment_methods' => collect(Expense::paymentMethods())->map(fn($l, $v) => ['value' => $v, 'label' => $l])->values(),
            ],
        ]);
    }

    private function authorizeTenant(Request $request, Expense $expense): void
    {
        abort_if($expense->tenant_id !== $request->user()->tenant_id, 403);
    }
}
