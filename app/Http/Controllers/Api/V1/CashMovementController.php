<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CashMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class CashMovementController extends Controller
{
    private function assertEnabled(): void
    {
        abort_unless(Auth::user()->tenant?->hasFeature('accounting'), 403,
            'La comptabilité n\'est pas activée sur votre abonnement.');
        abort_unless(Auth::user()->canDo('accounting', 'view'), 403, 'Permission refusée.');
    }

    public function index(Request $request): JsonResponse
    {
        $this->assertEnabled();
        $tenantId = Auth::user()->tenant_id;

        $query = CashMovement::where('tenant_id', $tenantId)->with('user:id,name');

        if ($request->filled('type'))       $query->where('type', $request->type);
        if ($request->filled('from'))       $query->whereDate('movement_date', '>=', $request->from);
        if ($request->filled('to'))         $query->whereDate('movement_date', '<=', $request->to);
        if ($search = $request->get('search')) {
            $query->where(fn($q) => $q->where('label', 'like', "%$search%")
                                      ->orWhere('notes', 'like', "%$search%"));
        }

        $movements = $query->orderByDesc('movement_date')->paginate($request->get('per_page', 20));

        $totals = CashMovement::where('tenant_id', $tenantId)
            ->when($request->filled('from'), fn($q) => $q->whereDate('movement_date', '>=', $request->from))
            ->when($request->filled('to'),   fn($q) => $q->whereDate('movement_date', '<=', $request->to))
            ->selectRaw('type, SUM(amount) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $totalIn  = ($totals['income'] ?? 0) + ($totals['contribution'] ?? 0);
        $totalOut = $totals['withdrawal'] ?? 0;

        return response()->json([
            'success' => true,
            'data'    => [
                'movements'  => $movements->map(fn($m) => $this->format($m)),
                'total_in'   => (float) $totalIn,
                'total_out'  => (float) $totalOut,
                'net'        => (float) ($totalIn - $totalOut),
                'meta'       => [
                    'total'        => $movements->total(),
                    'current_page' => $movements->currentPage(),
                    'last_page'    => $movements->lastPage(),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertEnabled();
        abort_unless(Auth::user()->canDo('accounting', 'create'), 403, 'Permission refusée.');

        $data = $request->validate([
            'type'           => ['required', Rule::in(array_keys(CashMovement::types()))],
            'label'          => 'required|string|max:255',
            'amount'         => 'required|numeric|min:0.01',
            'payment_method' => 'nullable|string|max:50',
            'movement_date'  => 'required|date',
            'notes'          => 'nullable|string|max:1000',
        ]);

        $movement = CashMovement::create([
            ...$data,
            'tenant_id' => Auth::user()->tenant_id,
            'user_id'   => Auth::id(),
            'payment_method' => $data['payment_method'] ?? 'cash',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Mouvement enregistré.',
            'data'    => ['movement' => $this->format($movement->load('user'))],
        ], 201);
    }

    public function destroy(CashMovement $cashMovement): JsonResponse
    {
        $this->assertEnabled();
        abort_unless(Auth::user()->canDo('accounting', 'delete'), 403, 'Permission refusée.');
        abort_if($cashMovement->tenant_id !== Auth::user()->tenant_id, 403);

        $cashMovement->delete();

        return response()->json(['success' => true, 'message' => 'Mouvement supprimé.']);
    }

    public function meta(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'types' => collect(CashMovement::types())
                    ->map(fn($l, $v) => ['value' => $v, 'label' => $l])
                    ->values(),
            ],
        ]);
    }

    private function format(CashMovement $m): array
    {
        return [
            'id'             => $m->id,
            'type'           => $m->type,
            'type_label'     => CashMovement::types()[$m->type] ?? $m->type,
            'is_income'      => in_array($m->type, CashMovement::incomeTypes()),
            'label'          => $m->label,
            'amount'         => $m->amount,
            'payment_method' => $m->payment_method,
            'movement_date'  => $m->movement_date?->toDateString(),
            'notes'          => $m->notes,
            'by'             => $m->user?->name,
            'created_at'     => $m->created_at->toIso8601String(),
        ];
    }
}
