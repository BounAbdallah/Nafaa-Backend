<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->tenant_id) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun espace de travail associé à ce compte.',
                'code'    => 'NO_TENANT',
            ], 403);
        }

        if (! $user->tenant || ! $user->tenant->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Votre espace de travail est inactif.',
                'code'    => 'TENANT_INACTIVE',
            ], 403);
        }

        // ── Blocage à l'expiration de l'abonnement ────────────────────────
        // Bloqué uniquement si une date d'expiration EST définie et dépassée,
        // et que l'espace n'est pas en période d'essai.
        // Les routes d'abonnement restent accessibles pour consulter/renouveler.
        $tenant  = $user->tenant;
        $expired = ! $tenant->isOnTrial()
            && $tenant->plan_expires_at
            && $tenant->plan_expires_at->isPast();

        if ($expired && ! $request->is('api/v1/subscription*')) {
            return response()->json([
                'success' => false,
                'message' => 'Votre abonnement a expiré. Veuillez le renouveler pour continuer.',
                'code'    => 'SUBSCRIPTION_EXPIRED',
            ], 403);
        }

        return $next($request);
    }
}
