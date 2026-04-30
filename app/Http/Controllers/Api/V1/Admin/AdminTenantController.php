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

    public function updateProfile(Request $request, Tenant $tenant): JsonResponse
    {
        $request->validate([
            'profile_type' => ['required', 'string', Rule::in([
                Tenant::PROFILE_MANUFACTURER,
                Tenant::PROFILE_RESELLER,
                Tenant::PROFILE_WHOLESALER,
                Tenant::PROFILE_SERVICE,
            ])],
        ]);

        $tenant->update([
            'profile_type' => $request->profile_type,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Profil de l\'espace de travail mis à jour.',
            'data'    => ['tenant' => new TenantResource($tenant->fresh())],
        ]);
    }
}
