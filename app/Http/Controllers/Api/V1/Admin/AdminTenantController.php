<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TenantResource;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminTenantController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Tenant::withoutGlobalScopes()
            ->withCount('users')
            ->latest();

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(fn ($q) =>
                $q->where('name', 'like', "%$search%")
                  ->orWhere('slug', 'like', "%$search%")
            );
        }

        if ($request->has('industry')) {
            $query->where('industry', $request->industry);
        }

        $tenants = $query->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data'    => [
                'tenants' => TenantResource::collection($tenants->items()),
                'meta'  => [
                    'total'        => $tenants->total(),
                    'per_page'     => $tenants->perPage(),
                    'current_page' => $tenants->currentPage(),
                    'last_page'    => $tenants->lastPage(),
                ],
            ],
        ]);
    }

    public function updateTenant(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'profile_type' => ['nullable', 'string', Rule::in([
                Tenant::PROFILE_MANUFACTURER,
                Tenant::PROFILE_RESELLER,
                Tenant::PROFILE_WHOLESALER,
                Tenant::PROFILE_SERVICE,
            ])],
            'plan' => ['nullable', 'string', Rule::in([
                Tenant::PLAN_DEMARRAGE,
                Tenant::PLAN_PRO,
                Tenant::PLAN_BUSINESS,
                Tenant::PLAN_ENTREPRISE,
            ])],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $tenant->update(array_filter($data, function($val) { return $val !== null; }));

        // If is_active is explicitly set to false/true, update it (array_filter removes false, so we need to handle it manually)
        if ($request->has('is_active')) {
            $tenant->update(['is_active' => $request->boolean('is_active')]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Espace de travail mis à jour.',
            'data'    => ['tenant' => new TenantResource($tenant->fresh())],
        ]);
    }
}
