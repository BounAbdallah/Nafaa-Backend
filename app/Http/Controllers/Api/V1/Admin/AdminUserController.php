<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::with(['tenant', 'roles'])
            ->withoutGlobalScopes()
            ->latest();

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

    public function show(User $user): JsonResponse
    {
        $user->loadMissing(['tenant', 'roles']);

        return response()->json([
            'success' => true,
            'data'    => ['user' => new UserResource($user)],
        ]);
    }

    public function block(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

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

        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => "L'utilisateur {$user->name} a été bloqué.",
            'data'    => ['user' => new UserResource($user->fresh(['tenant', 'roles']))],
        ]);
    }

    public function unblock(User $user): JsonResponse
    {
        $user->update([
            'is_active'    => true,
            'block_reason' => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => "L'utilisateur {$user->name} a été débloqué.",
            'data'    => ['user' => new UserResource($user->fresh(['tenant', 'roles']))],
        ]);
    }

    public function stats(): JsonResponse
    {
        $stats = [
            'total_users'   => User::withoutGlobalScopes()->count(),
            'active_users'  => User::withoutGlobalScopes()->where('is_active', true)->count(),
            'blocked_users' => User::withoutGlobalScopes()->where('is_active', false)->count(),
            'unverified'    => User::withoutGlobalScopes()->whereNull('email_verified_at')->count(),
            'super_admins'  => User::withoutGlobalScopes()->role('super_admin')->count(),
        ];

        return response()->json([
            'success' => true,
            'data'    => ['stats' => $stats],
        ]);
    }
}
