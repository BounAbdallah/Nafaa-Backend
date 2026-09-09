<?php

namespace App\Http\Controllers\Api\V1\Suppliers;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SupplierResource;
use App\Models\Customer;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupplierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $query    = Supplier::where('tenant_id', $tenantId);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) =>
                $q->where('name', 'like', "%$s%")
                  ->orWhere('phone', 'like', "%$s%")
                  ->orWhere('email', 'like', "%$s%")
                  ->orWhere('contact_name', 'like', "%$s%")
            );
        }
        if ($request->filled('active')) $query->where('is_active', $request->boolean('active'));

        $query->orderBy('name');
        $suppliers = $query->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data'    => [
                'suppliers' => SupplierResource::collection($suppliers->items()),
                'meta'      => [
                    'total'        => $suppliers->total(),
                    'per_page'     => $suppliers->perPage(),
                    'current_page' => $suppliers->currentPage(),
                    'last_page'    => $suppliers->lastPage(),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->canDo('suppliers', 'create'), 403, 'Permission refusée.');
        $data = $request->validate([
            'name'         => 'required|string|max:255',
            'phone'        => 'nullable|string|max:30',
            'email'        => 'nullable|email|max:255',
            'contact_name' => 'nullable|string|max:255',
            'address'      => 'nullable|string',
            'city'         => 'nullable|string|max:100',
            'country'      => ['nullable', Rule::in(array_keys(Customer::countries()))],
            'notes'        => 'nullable|string',
        ]);

        $supplier = Supplier::create([
            ...$data,
            'tenant_id' => $request->user()->tenant_id,
            'country'   => $data['country'] ?? 'SN',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Fournisseur créé.',
            'data'    => ['supplier' => new SupplierResource($supplier)],
        ], 201);
    }

    public function show(Request $request, Supplier $supplier): JsonResponse
    {
        $this->authorizeTenant($request, $supplier);
        return response()->json(['success' => true, 'data' => ['supplier' => new SupplierResource($supplier)]]);
    }

    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        $this->authorizeTenant($request, $supplier);
        abort_unless($request->user()->canDo('suppliers', 'edit'), 403, 'Permission refusée.');

        $data = $request->validate([
            'name'         => 'sometimes|string|max:255',
            'phone'        => 'nullable|string|max:30',
            'email'        => 'nullable|email|max:255',
            'contact_name' => 'nullable|string|max:255',
            'address'      => 'nullable|string',
            'city'         => 'nullable|string|max:100',
            'country'      => ['nullable', Rule::in(array_keys(Customer::countries()))],
            'notes'        => 'nullable|string',
            'is_active'    => 'boolean',
        ]);

        $supplier->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Fournisseur mis à jour.',
            'data'    => ['supplier' => new SupplierResource($supplier->fresh())],
        ]);
    }

    public function destroy(Request $request, Supplier $supplier): JsonResponse
    {
        $this->authorizeTenant($request, $supplier);
        abort_unless($request->user()->canDo('suppliers', 'delete'), 403, 'Permission refusée.');

        // Bloquer la suppression si des BDC actifs existent
        $hasActiveOrders = $supplier->purchaseOrders()
            ->whereNotIn('status', ['received', 'cancelled'])
            ->exists();

        abort_if($hasActiveOrders, 422, 'Impossible de supprimer un fournisseur ayant des bons de commande en cours.');

        $supplier->delete();
        return response()->json(['success' => true, 'message' => 'Fournisseur supprimé.']);
    }

    public function meta(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'countries' => collect(Customer::countries())->map(fn($l, $v) => ['value' => $v, 'label' => $l])->values(),
            ],
        ]);
    }

    private function authorizeTenant(Request $request, Supplier $supplier): void
    {
        abort_if($supplier->tenant_id !== $request->user()->tenant_id, 403);
    }
}
