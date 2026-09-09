<?php

namespace App\Http\Controllers\Api\V1\Customers;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $query    = Customer::where('tenant_id', $tenantId);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) =>
                $q->where('name', 'like', "%$s%")
                  ->orWhere('email', 'like', "%$s%")
                  ->orWhere('phone', 'like', "%$s%")
                  ->orWhere('company', 'like', "%$s%")
            );
        }
        if ($request->filled('type'))   $query->where('type', $request->type);
        if ($request->filled('active')) $query->where('is_active', $request->boolean('active'));

        $sortMap = ['name' => 'name', 'spent' => 'total_spent', 'orders' => 'orders_count', 'date' => 'created_at'];
        $sort  = $sortMap[$request->get('sort', 'date')] ?? 'created_at';
        $order = $request->get('order', 'desc') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $order);

        $customers = $query->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data'    => [
                'customers' => CustomerResource::collection($customers->items()),
                'meta'      => [
                    'total'        => $customers->total(),
                    'per_page'     => $customers->perPage(),
                    'current_page' => $customers->currentPage(),
                    'last_page'    => $customers->lastPage(),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->canDo('customers', 'create'), 403, 'Permission refusée.');
        $data = $request->validate([
            'name'    => 'required|string|max:255',
            'email'   => 'nullable|email|max:255',
            'phone'   => 'nullable|string|max:30',
            'company' => 'nullable|string|max:255',
            'type'    => ['required', Rule::in(['individual', 'company'])],
            'address' => 'nullable|string',
            'city'    => 'nullable|string|max:100',
            'country' => ['nullable', Rule::in(array_keys(Customer::countries()))],
            'notes'   => 'nullable|string',
        ]);

        $customer = Customer::create([
            ...$data,
            'tenant_id' => $request->user()->tenant_id,
            'country'   => $data['country'] ?? 'SN',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Client créé avec succès.',
            'data'    => ['customer' => new CustomerResource($customer)],
        ], 201);
    }

    public function show(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeTenant($request, $customer);
        $customer->load(['orders' => fn($q) => $q->latest()]);
        return response()->json(['success' => true, 'data' => ['customer' => new CustomerResource($customer)]]);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeTenant($request, $customer);
        abort_unless($request->user()->canDo('customers', 'edit'), 403, 'Permission refusée.');

        $data = $request->validate([
            'name'    => 'sometimes|string|max:255',
            'email'   => 'nullable|email|max:255',
            'phone'   => 'nullable|string|max:30',
            'company' => 'nullable|string|max:255',
            'type'    => ['sometimes', Rule::in(['individual', 'company'])],
            'address' => 'nullable|string',
            'city'    => 'nullable|string|max:100',
            'country' => ['nullable', Rule::in(array_keys(Customer::countries()))],
            'notes'   => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $customer->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Client mis à jour.',
            'data'    => ['customer' => new CustomerResource($customer->fresh())],
        ]);
    }

    public function destroy(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeTenant($request, $customer);
        abort_unless($request->user()->canDo('customers', 'delete'), 403, 'Permission refusée.');
        $customer->delete();
        return response()->json(['success' => true, 'message' => 'Client supprimé.']);
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

    private function authorizeTenant(Request $request, Customer $customer): void
    {
        abort_if($customer->tenant_id !== $request->user()->tenant_id, 403);
    }
}
