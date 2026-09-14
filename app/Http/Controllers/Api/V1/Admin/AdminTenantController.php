<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Concerns\ScopesByCountry;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TenantResource;
use App\Models\Tenant;
use App\Notifications\AccountActivatedNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminTenantController extends Controller
{
    use ScopesByCountry;

    public function index(Request $request): JsonResponse
    {
        $query = $this->scopeTenantsByCountry(Tenant::withoutGlobalScopes(), $request->user())
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
        $baseQuery    = $this->scopeTenantsByCountry(Tenant::withoutGlobalScopes(), $request->user());
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
        $this->assertCanManageTenant(request()->user(), $tenant);
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
        $this->assertCanManageTenant($request->user(), $tenant);
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

        // ⚠️  Capturer l'état AVANT toute modification
        $wasInactive = ! $tenant->is_active;

        if (isset($data['pack_id'])) {
            $pack = \App\Models\Pack::find($data['pack_id']);
            $data['plan'] = $pack->slug;
            if ($pack->features) {
                $currentSettings = $tenant->settings ?? [];
                $currentSettings['enabled_modules'] = $pack->features;
                $data['settings'] = $currentSettings;
            }
        }

        // Appliquer tous les champs non-null (array_filter retire false → is_active géré séparément)
        $filteredData = array_filter($data, fn($val) => $val !== null && $val !== false);
        unset($filteredData['is_active']); // toujours géré ci-dessous
        if (!empty($filteredData)) {
            $tenant->update($filteredData);
        }

        // Gérer is_active séparément pour ne pas perdre la valeur false
        if ($request->has('is_active')) {
            $becomesActive = $request->boolean('is_active');
            $tenant->update(['is_active' => $becomesActive]);

            // Envoyer l'e-mail d'activation uniquement lors du 1er passage false → true
            if ($wasInactive && $becomesActive) {
                try {
                    $owner = $tenant->fresh()->load('owner')->owner;
                    if ($owner) {
                        $owner->notify(new AccountActivatedNotification($tenant->fresh()));
                    }
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning('Failed to send activation email: ' . $e->getMessage());
                }
            }
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
        $this->assertCanManageTenant($request->user(), $tenant);
        $data = $request->validate([
            'enabled_modules'   => ['required', 'array'],
            'enabled_modules.*' => ['string'],
            'features'          => ['nullable', 'array'],          // fonctionnalités optionnelles (ex: credit)
            'features.*'        => ['string'],
        ]);

        $settings = $tenant->settings ?? [];
        $settings['enabled_modules'] = $data['enabled_modules'];
        if ($request->exists('features')) {
            $settings['features'] = array_values($data['features'] ?? []);
        }
        $tenant->update(['settings' => $settings]);

        return response()->json([
            'success' => true,
            'message' => 'Modules mis à jour.',
            'data'    => ['tenant' => new TenantResource($tenant->fresh())],
        ]);
    }

    /**
     * Supprime un espace (corbeille). Ses utilisateurs perdent l'accès.
     */
    public function destroy(Request $request, Tenant $tenant): JsonResponse
    {
        $this->assertCanManageTenant($request->user(), $tenant);

        // Révoquer les sessions de tous les membres de l'espace
        \App\Models\User::where('tenant_id', $tenant->id)->each(fn ($u) => $u->tokens()->delete());

        $tenant->delete();

        return response()->json(['success' => true, 'message' => "L'espace {$tenant->name} a été déplacé dans la corbeille."]);
    }

    /**
     * Liste les espaces supprimés (corbeille).
     */
    public function trashed(Request $request): JsonResponse
    {
        $query = Tenant::onlyTrashed()->withoutGlobalScopes()->with('owner')->latest('deleted_at');

        // Scoping pays
        $this->scopeTenantsByCountry($query, $request->user());

        if ($search = $request->get('search')) {
            $query->where('name', 'like', "%$search%");
        }

        $tenants = $query->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data'    => [
                'tenants' => collect($tenants->items())->map(fn ($t) => [
                    'id'         => $t->id,
                    'name'       => $t->name,
                    'owner'      => $t->owner?->name,
                    'owner_email'=> $t->owner?->email,
                    'country'    => $t->settings['country'] ?? null,
                    'deleted_at' => $t->deleted_at?->toIso8601String(),
                ]),
                'meta'  => [
                    'total'        => $tenants->total(),
                    'per_page'     => $tenants->perPage(),
                    'current_page' => $tenants->currentPage(),
                    'last_page'    => $tenants->lastPage(),
                ],
            ],
        ]);
    }

    /**
     * Restaure un espace depuis la corbeille.
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        $tenant = Tenant::onlyTrashed()->withoutGlobalScopes()->findOrFail($id);
        $this->assertCanManageTenant($request->user(), $tenant);
        $tenant->restore();

        return response()->json(['success' => true, 'message' => "L'espace {$tenant->name} a été restauré."]);
    }

    /**
     * Supprime définitivement un espace et tous ses utilisateurs.
     */
    public function forceDelete(Request $request, int $id): JsonResponse
    {
        $tenant = Tenant::onlyTrashed()->withoutGlobalScopes()->findOrFail($id);
        $this->assertCanManageTenant($request->user(), $tenant);

        $name = $tenant->name;
        \App\Models\User::withTrashed()->where('tenant_id', $tenant->id)->each(function ($u) {
            $u->tokens()->delete();
            $u->forceDelete();
        });
        $tenant->forceDelete();

        return response()->json(['success' => true, 'message' => "L'espace {$name} a été supprimé définitivement."]);
    }
}
