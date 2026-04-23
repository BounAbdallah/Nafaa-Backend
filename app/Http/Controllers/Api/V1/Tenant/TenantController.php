<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Tenant\CreateTenantRequest;
use App\Http\Resources\Api\V1\TenantResource;
use App\Services\Tenant\TenantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    public function __construct(private readonly TenantService $tenantService) {}

    public function store(CreateTenantRequest $request): JsonResponse
    {
        if ($request->user()->tenant_id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous avez déjà un espace de travail.',
            ], 422);
        }

        $tenant = $this->tenantService->createTenant($request->user(), $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Espace de travail créé avec succès.',
            'data'    => [
                'tenant' => new TenantResource($tenant),
            ],
        ], 201);
    }

    public function current(Request $request): JsonResponse
    {
        $tenant = $this->tenantService->getCurrentTenant($request->user());

        if (! $tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun espace de travail trouvé.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'tenant' => new TenantResource($tenant),
            ],
        ]);
    }

    public function industries(): JsonResponse
    {
        $industries = collect(\App\Models\Tenant::INDUSTRIES)
            ->map(fn ($label, $key) => ['value' => $key, 'label' => $label])
            ->values();

        return response()->json([
            'success' => true,
            'data'    => ['industries' => $industries],
        ]);
    }
}
