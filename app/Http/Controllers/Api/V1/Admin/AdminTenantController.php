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
            ->with(['owner', 'pack'])
            ->withCount('users')
            ->latest();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(fn ($q) =>
                $q->where('name', 'like', "%$search%")
                  ->orWhere('slug', 'like', "%$search%")
            );
        }

        if ($request->filled('industry')) {
            $query->where('industry', $request->industry);
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->status === 'active');
        }

        // Compteurs globaux (avant pagination)
        $baseQuery    = Tenant::withoutGlobalScopes();
        $activeCount  = (clone $baseQuery)->where('is_active', true)->count();
        $inactiveCount = (clone $baseQuery)->where('is_active', false)->count();

        $tenants = $query->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data'    => [
                'tenants' => TenantResource::collection($tenants->items()),
                'meta'    => [
                    'total'          => $tenants->total(),
                    'per_page'       => $tenants->perPage(),
                    'current_page'   => $tenants->currentPage(),
                    'last_page'      => $tenants->lastPage(),
                    'active_count'   => $activeCount,
                    'inactive_count' => $inactiveCount,
                ],
            ],
        ]);
    }

    public function show(Tenant $tenant): JsonResponse
    {
        $tenant->loadMissing(['owner', 'pack']);
        $tenant->loadCount('users');

        // Membres du tenant
        $members = \App\Models\User::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->with('roles')
            ->latest()
            ->get()
            ->map(fn ($u) => [
                'id'                => $u->id,
                'name'              => $u->name,
                'email'             => $u->email,
                'role'              => $u->roles->first()?->name ?? 'member',
                'email_verified_at' => $u->email_verified_at,
                'created_at'        => $u->created_at,
            ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'tenant'  => new TenantResource($tenant),
                'members' => $members,
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
            'settings'  => ['nullable', 'array'],
            'pack_id'   => ['nullable', 'exists:packs,id'],
        ]);

        if (isset($data['pack_id'])) {
            $pack = \App\Models\Pack::find($data['pack_id']);
            $data['plan'] = $pack->slug;
            // Also apply pack features to tenant settings if needed
            if ($pack->features) {
                $currentSettings = $tenant->settings ?? [];
                $currentSettings['enabled_modules'] = $pack->features;
                $data['settings'] = $currentSettings;
            }
        }

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

    /**
     * Override individual modules for a tenant (super admin only).
     * Preserves all other settings fields.
     */
    public function updateModules(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'enabled_modules'   => ['required', 'array'],
            'enabled_modules.*' => ['string'],
        ]);

        $settings = $tenant->settings ?? [];
        $settings['enabled_modules'] = $data['enabled_modules'];
        $tenant->update(['settings' => $settings]);

        return response()->json([
            'success' => true,
            'message' => 'Modules mis à jour.',
            'data'    => ['tenant' => new TenantResource($tenant->fresh())],
        ]);
    }
}
