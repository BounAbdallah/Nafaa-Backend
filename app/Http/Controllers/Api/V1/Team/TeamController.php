<?php

namespace App\Http\Controllers\Api\V1\Team;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Services\Team\TeamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TeamController extends Controller
{
    public function __construct(private readonly TeamService $teamService) {}

    public function index(Request $request): JsonResponse
    {
        $tenant  = $request->user()->tenant;
        $members = $this->teamService->listMembers($tenant);

        return response()->json([
            'success' => true,
            'data'    => [
                'members' => UserResource::collection($members),
                'count'   => $members->count(),
            ],
        ]);
    }

    public function invite(Request $request): JsonResponse
    {
        $request->validate([
            'name'  => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'role'  => ['required', Rule::in(['admin', 'employee', 'viewer'])],
        ]);

        $tenant = $request->user()->tenant;
        $result = $this->teamService->invite($tenant, $request->only('name', 'email', 'role'), $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Invitation envoyée avec succès.',
            'data'    => [
                'user'          => new UserResource($result['user']),
                'temp_password' => $result['temp_password'],
            ],
        ], 201);
    }

    public function updateRole(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'role' => ['required', Rule::in(['admin', 'employee', 'viewer'])],
        ]);

        $tenant  = $request->user()->tenant;
        $updated = $this->teamService->updateRole($tenant, $user, $request->role, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Rôle mis à jour.',
            'data'    => ['user' => new UserResource($updated)],
        ]);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $tenant = $request->user()->tenant;
        abort_unless($user->tenant_id === $tenant->id, 403);

        $user->load('roles');
        $logs = $this->teamService->getActivityLogs($tenant, 10, $user->id);

        $months = collect(range(5, 0))->map(function ($i) {
            $date = now()->startOfMonth()->subMonths($i);
            return [
                'date_start' => $date->format('Y-m-d'),
                'date_end'   => $date->copy()->endOfMonth()->format('Y-m-d'),
                'label'      => $date->translatedFormat('M'),
                'ventes'     => 0,
            ];
        });

        $salesData = \Illuminate\Support\Facades\DB::table('orders')
            ->where('user_id', $user->id)
            ->where('tenant_id', $tenant->id)
            ->where('status', '!=', 'cancelled')
            ->selectRaw('DATE_FORMAT(created_at, "%Y-%m") as month, SUM(total_amount) as total')
            ->groupBy('month')
            ->get()
            ->pluck('total', 'month');

        $performance = $months->map(function ($m) use ($salesData) {
            $monthKey = substr($m['date_start'], 0, 7);
            return [
                'name'   => $m['label'],
                'Ventes' => (float)($salesData[$monthKey] ?? 0),
            ];
        });

        // Lifetime stats
        $totalSalesAmount = \Illuminate\Support\Facades\DB::table('orders')
            ->where('user_id', $user->id)
            ->where('tenant_id', $tenant->id)
            ->where('status', '!=', 'cancelled')
            ->sum('total_amount');

        $totalOrdersCount = \Illuminate\Support\Facades\DB::table('orders')
            ->where('user_id', $user->id)
            ->where('tenant_id', $tenant->id)
            ->where('status', '!=', 'cancelled')
            ->count();

        $totalPurchaseAmount = \Illuminate\Support\Facades\DB::table('purchase_orders')
            ->where('user_id', $user->id)
            ->where('tenant_id', $tenant->id)
            ->where('status', '!=', 'cancelled')
            ->sum('total_amount');

        $totalExpensesAmount = \Illuminate\Support\Facades\DB::table('expenses')
            ->where('user_id', $user->id)
            ->where('tenant_id', $tenant->id)
            ->sum('amount');

        return response()->json([
            'success' => true,
            'data'    => [
                'member'         => new UserResource($user),
                'performance'    => $performance,
                'lifetime_stats' => [
                    'total_sales'    => (float)$totalSalesAmount,
                    'orders_count'   => $totalOrdersCount,
                    'total_expenses' => (float)($totalPurchaseAmount + $totalExpensesAmount),
                ],
                'activity'       => $logs->map(fn ($log) => [
                    'id'         => $log->id,
                    'action'     => $log->action,
                    'properties' => $log->properties,
                    'created_at' => $log->created_at->toIso8601String(),
                ]),
            ],
        ]);
    }

    public function remove(Request $request, User $user): JsonResponse
    {
        $tenant = $request->user()->tenant;
        $this->teamService->removeMember($tenant, $user, $request->user());

        return response()->json([
            'success' => true,
            'message' => "{$user->name} a été retiré de l'équipe.",
        ]);
    }

    public function activity(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;
        $logs   = $this->teamService->getActivityLogs($tenant, min(100, $request->get('limit', 20)));

        return response()->json([
            'success' => true,
            'data'    => [
                'logs' => $logs->map(fn ($log) => [
                    'id'         => $log->id,
                    'action'     => $log->action,
                    'properties' => $log->properties,
                    'user'       => $log->user ? [
                        'id'   => $log->user->id,
                        'name' => $log->user->name,
                    ] : null,
                    'created_at' => $log->created_at->toIso8601String(),
                ]),
            ],
        ]);
    }
}
