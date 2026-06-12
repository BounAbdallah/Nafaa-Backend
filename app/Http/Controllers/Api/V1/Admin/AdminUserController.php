<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Concerns\ScopesByCountry;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AdminUserController extends Controller
{
    use ScopesByCountry;

    public function index(Request $request): JsonResponse
    {
        $query = User::with(['tenant', 'roles'])
            ->withoutGlobalScopes()
            ->role('admin') // Only show shop administrators
            ->latest();

        $this->scopeUsersByCountry($query, $request->user());

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(fn ($q) =>
                $q->where('name', 'like', "%$search%")
                  ->orWhere('email', 'like', "%$search%")
            );
        }

        if ($request->has('status')) {
            $query->where('is_active', $request->status === 'active');
        }

        if ($request->has('tenant_id')) {
            $query->where('tenant_id', $request->tenant_id);
        }

        if ($request->has('tenant_status')) {
            $status = $request->tenant_status === 'active';
            $query->whereHas('tenant', function($q) use ($status) {
                $q->withoutGlobalScopes()->where('is_active', $status);
            });
        }

        $users = $query->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data'    => [
                'users' => UserResource::collection($users->items()),
                'meta'  => [
                    'total'        => $users->total(),
                    'per_page'     => $users->perPage(),
                    'current_page' => $users->currentPage(),
                    'last_page'    => $users->lastPage(),
                ],
            ],
        ]);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $user->loadMissing(['tenant', 'roles']);
        $this->assertCanManageUser($request->user(), $user);

        $logs = \App\Models\ActivityLog::where('user_id', $user->id)
            ->where('action', 'login')
            ->latest()
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'user' => new UserResource($user),
                'logs' => $logs,
            ],
        ]);
    }

    public function block(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $this->assertCanManageUser($request->user(), $user);

        if ($user->hasRole('super_admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de bloquer un super administrateur.',
            ], 403);
        }

        $user->update([
            'is_active'    => false,
            'block_reason' => $request->reason,
        ]);

        // If the user is the owner of their tenant, block the tenant too
        if ($user->tenant && $user->tenant->owner_id === $user->id) {
            $user->tenant->update(['is_active' => false]);
        }

        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => "L'utilisateur {$user->name} a été bloqué.",
            'data'    => ['user' => new UserResource($user->fresh(['tenant', 'roles']))],
        ]);
    }

    public function unblock(Request $request, User $user): JsonResponse
    {
        $this->assertCanManageUser($request->user(), $user);

        $user->update([
            'is_active'    => true,
            'block_reason' => null,
        ]);

        // If the user is the owner of their tenant, unblock the tenant too
        if ($user->tenant && $user->tenant->owner_id === $user->id) {
            $user->tenant->update(['is_active' => true]);
        }

        return response()->json([
            'success' => true,
            'message' => "L'utilisateur {$user->name} a été débloqué.",
            'data'    => ['user' => new UserResource($user->fresh(['tenant', 'roles']))],
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $admin = $request->user();
        $tenantQ = fn () => $this->scopeTenantsByCountry(Tenant::withoutGlobalScopes(), $admin);
        $userQ   = fn () => $this->scopeUsersByCountry(User::withoutGlobalScopes(), $admin);

        // 1. Basic Stats
        $totalTenants = $tenantQ()->count();
        $totalUsers   = $userQ()->count();
        
        // 2. DB Size Mock (based on records)
        // In a real app, you might query INFORMATION_SCHEMA or use a library
        $mockDbSize = ($totalTenants * 1.2) + ($totalUsers * 0.05) + 15.4; // MB
        
        // 3. Tenants by Industry
        $tenantsByType = $tenantQ()
            ->select('industry', DB::raw('count(*) as count'))
            ->groupBy('industry')
            ->get()
            ->mapWithKeys(fn ($item) => [$item->industry => $item->count]);

        // 4. Registration Graph Data (Last 30 days)
        $registrations = $tenantQ()
            ->where('created_at', '>=', now()->subDays(30))
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('count(*) as count'))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $stats = [
            'total_users'    => $totalUsers,
            'active_users'   => $userQ()->where('is_active', true)->count(),
            'blocked_users'  => $userQ()->where('is_active', false)->count(),
            'total_tenants'     => $totalTenants,
            'pending_approvals' => $tenantQ()->where('is_active', false)->count(),
            'db_size_mb'        => round($mockDbSize, 2),
            'tenants_by_type'   => $tenantsByType,
            'graph_data'        => $registrations,
            'super_admins'      => User::withoutGlobalScopes()->role('super_admin')->count(),
        ];

        return response()->json([
            'success' => true,
            'data'    => ['stats' => $stats],
        ]);
    }
}
