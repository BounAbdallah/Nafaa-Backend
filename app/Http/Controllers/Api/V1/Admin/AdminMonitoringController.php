<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Concerns\ScopesByCountry;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminMonitoringController extends Controller
{
    use ScopesByCountry;

    /** Applique le filtre pays aux logs (via le tenant du log). */
    private function scopeLogsByCountry($query, $user)
    {
        if ($country = $this->effectiveCountry($user)) {
            $query->whereHas('tenant', fn ($q) => $q->where('settings->country', $country));
        }
        return $query;
    }

    /**
     * Liste paginée des dernières connexions (action = login).
     * Filtres : search (nom/email), tenant_id, user_id, date_from, date_to.
     */
    public function logins(Request $request): JsonResponse
    {
        $query = ActivityLog::query()
            ->where('action', 'login')
            ->with([
                'user:id,name,email,tenant_id,is_active',
                'tenant:id,name',
            ])
            ->latest();

        $this->scopeLogsByCountry($query, $request->user());

        if ($search = $request->get('search')) {
            $query->whereHas('user', function ($q) use ($search) {
                $q->withoutGlobalScopes()
                  ->where(fn ($qq) => $qq
                      ->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%"));
            });
        }

        if ($tenantId = $request->get('tenant_id')) {
            $query->where('tenant_id', $tenantId);
        }

        if ($userId = $request->get('user_id')) {
            $query->where('user_id', $userId);
        }

        if ($from = $request->get('date_from')) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to = $request->get('date_to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        $logins = $query->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data'    => [
                'logins' => collect($logins->items())->map(fn ($log) => [
                    'id'         => $log->id,
                    'user'       => $log->user ? [
                        'id'        => $log->user->id,
                        'name'      => $log->user->name,
                        'email'     => $log->user->email,
                        'is_active' => (bool) $log->user->is_active,
                    ] : null,
                    'tenant'     => $log->tenant ? [
                        'id'   => $log->tenant->id,
                        'name' => $log->tenant->name,
                    ] : null,
                    'ip_address' => $log->ip_address,
                    'user_agent' => $log->properties['user_agent'] ?? null,
                    'created_at' => $log->created_at->toIso8601String(),
                ]),
                'meta' => [
                    'total'        => $logins->total(),
                    'per_page'     => $logins->perPage(),
                    'current_page' => $logins->currentPage(),
                    'last_page'    => $logins->lastPage(),
                ],
            ],
        ]);
    }

    /**
     * Fréquence globale de connexion (tous utilisateurs confondus) :
     * connexions et utilisateurs uniques par jour sur N jours (défaut 30).
     */
    public function globalFrequency(Request $request): JsonResponse
    {
        $days = min(365, max(7, (int) $request->get('days', 30)));
        $from = now()->subDays($days - 1)->startOfDay();

        $rows = $this->scopeLogsByCountry(ActivityLog::query(), $request->user())
            ->where('action', 'login')
            ->where('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as logins, COUNT(DISTINCT user_id) as users')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $series = collect(range(0, $days - 1))->map(function ($i) use ($from, $rows) {
            $date = $from->copy()->addDays($i);
            $key  = $date->format('Y-m-d');
            return [
                'date'   => $key,
                'label'  => $date->translatedFormat('d M'),
                'logins' => (int) ($rows[$key]->logins ?? 0),
                'users'  => (int) ($rows[$key]->users ?? 0),
            ];
        });

        $totalLogins = $series->sum('logins');
        $uniqueUsers = ActivityLog::where('action', 'login')
            ->where('created_at', '>=', $from)
            ->distinct('user_id')
            ->count('user_id');

        return response()->json([
            'success' => true,
            'data'    => [
                'days'          => $days,
                'series'        => $series,
                'total_logins'  => $totalLogins,
                'unique_users'  => $uniqueUsers,
                'avg_per_day'   => round($totalLogins / $days, 1),
                'peak_day'      => $series->sortByDesc('logins')->first(),
            ],
        ]);
    }

    /**
     * Fréquence de connexion d'un utilisateur :
     * connexions par jour sur N jours (défaut 30) + statistiques globales.
     */
    public function loginFrequency(Request $request, User $user): JsonResponse
    {
        $this->assertCanManageUser($request->user(), $user);

        $days = min(365, max(7, (int) $request->get('days', 30)));
        $from = now()->subDays($days - 1)->startOfDay();

        $rows = ActivityLog::query()
            ->where('action', 'login')
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as count')
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('count', 'day');

        // Série complète jour par jour (0 si aucune connexion)
        $series = collect(range(0, $days - 1))->map(function ($i) use ($from, $rows) {
            $date = $from->copy()->addDays($i);
            $key  = $date->format('Y-m-d');
            return [
                'date'  => $key,
                'label' => $date->translatedFormat('d M'),
                'count' => (int) ($rows[$key] ?? 0),
            ];
        });

        $totalPeriod = $series->sum('count');
        $activeDays  = $series->where('count', '>', 0)->count();
        $totalAll    = ActivityLog::where('action', 'login')->where('user_id', $user->id)->count();

        return response()->json([
            'success' => true,
            'data'    => [
                'user' => [
                    'id'            => $user->id,
                    'name'          => $user->name,
                    'email'         => $user->email,
                    'last_login_at' => $user->last_login_at?->toIso8601String(),
                ],
                'days'          => $days,
                'series'        => $series,
                'total_period'  => $totalPeriod,
                'active_days'   => $activeDays,
                'avg_per_week'  => $days >= 7 ? round($totalPeriod / ($days / 7), 1) : $totalPeriod,
                'total_all'     => $totalAll,
            ],
        ]);
    }

    /**
     * Sessions en cours : tokens Sanctum actifs (= utilisateurs connectés).
     * « En ligne » si activité dans les 15 dernières minutes.
     */
    public function activeSessions(Request $request): JsonResponse
    {
        $onlineThreshold = now()->subMinutes(15);

        $query = \Illuminate\Support\Facades\DB::table('personal_access_tokens as pat')
            ->join('users', 'users.id', '=', 'pat.tokenable_id')
            ->leftJoin('tenants', 'tenants.id', '=', 'users.tenant_id')
            ->where('pat.tokenable_type', \App\Models\User::class)
            ->whereNull('users.deleted_at')
            ->select(
                'pat.id', 'pat.created_at', 'pat.last_used_at',
                'users.id as user_id', 'users.name', 'users.email',
                'tenants.name as tenant_name'
            )
            ->orderByDesc('pat.last_used_at');

        // Filtre pays (admin pays verrouillé, super admin via ?country=)
        if ($country = $this->effectiveCountry($request->user())) {
            $query->where('tenants.settings->country', $country);
        }

        $tokens = $query->paginate($request->get('per_page', 20));

        $sessions = collect($tokens->items())->map(function ($t) use ($onlineThreshold) {
            $last = $t->last_used_at ? \Carbon\Carbon::parse($t->last_used_at) : \Carbon\Carbon::parse($t->created_at);
            return [
                'id'            => $t->id,
                'user'          => ['id' => $t->user_id, 'name' => $t->name, 'email' => $t->email],
                'tenant'        => $t->tenant_name,
                'started_at'    => \Carbon\Carbon::parse($t->created_at)->toIso8601String(),
                'last_activity' => $last->toIso8601String(),
                'is_online'     => $last->gte($onlineThreshold),
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => [
                'sessions'     => $sessions->values(),
                'online_count' => $sessions->where('is_online', true)->count(),
                'meta'         => [
                    'total'        => $tokens->total(),
                    'per_page'     => $tokens->perPage(),
                    'current_page' => $tokens->currentPage(),
                    'last_page'    => $tokens->lastPage(),
                ],
            ],
        ]);
    }

    /**
     * Termine une session à distance (déconnecte l'utilisateur).
     */
    public function revokeSession(Request $request, int $id): JsonResponse
    {
        $token = \Laravel\Sanctum\PersonalAccessToken::with('tokenable.tenant')->findOrFail($id);

        // Scoping pays : un admin pays ne révoque que les sessions de son pays
        if ($country = $this->effectiveCountry($request->user())) {
            $tenantCountry = $token->tokenable?->tenant?->settings['country'] ?? null;
            abort_if($tenantCountry !== $country, 403, 'Session hors de votre pays.');
        }

        $token->delete();

        return response()->json(['success' => true, 'message' => 'Session terminée — l\'utilisateur a été déconnecté.']);
    }
}
