<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Pack;
use App\Models\PlanChangeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    /**
     * Détails de l'abonnement de l'espace courant :
     * pack, prix effectif (remise éventuelle), essai, expiration,
     * historique des paiements et demande de changement en cours.
     */
    public function show(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant()->with('pack.countryPrices')->first();

        abort_if(!$tenant, 404, 'Aucun espace de travail.');

        $year = (int) $request->get('year', date('Y'));

        $payments = $tenant->payments()
            ->where('year', $year)
            ->orderBy('month')
            ->get()
            ->map(fn ($p) => [
                'id'      => $p->id,
                'month'   => $p->month,
                'year'    => $p->year,
                'amount'  => (float) $p->amount,
                'status'  => $p->status,
                'paid_at' => $p->paid_at?->toIso8601String(),
                'method'  => $p->payment_method,
            ]);

        // Le mois courant est-il payé ?
        $currentMonthPaid = $tenant->payments()
            ->where('year', (int) date('Y'))
            ->where('month', (int) date('n'))
            ->where('status', 'paid')
            ->exists();

        $pendingRequest = PlanChangeRequest::with('requestedPack:id,name,price')
            ->where('tenant_id', $tenant->id)
            ->where('status', 'pending')
            ->latest()
            ->first();

        $tenantCountry  = $tenant->settings['country'] ?? null;
        $localPack      = $tenant->pack?->priceFor($tenantCountry);
        $packPrice      = (float) ($localPack['price'] ?? 0);
        $effectivePrice = $tenant->getEffectivePrice();

        return response()->json([
            'success' => true,
            'data'    => [
                'pack' => $tenant->pack ? [
                    'id'       => $tenant->pack->id,
                    'name'     => $tenant->pack->name,
                    'price'    => $packPrice,
                    'currency' => $localPack['currency'] ?? 'XOF',
                    'period'   => $tenant->pack->period,
                    'features' => $tenant->pack->features,
                ] : null,
                'effective_price'    => $effectivePrice,
                'has_discount'       => $tenant->custom_price !== null && $effectivePrice < $packPrice,
                'is_on_trial'        => $tenant->isOnTrial(),
                'trial_ends_at'      => $tenant->trial_ends_at?->toIso8601String(),
                'trial_days_left'    => $tenant->isOnTrial() ? (int) ceil(now()->diffInDays($tenant->trial_ends_at, false)) : 0,
                'has_active_plan'    => $tenant->hasActivePlan(),
                'plan_expires_at'    => $tenant->plan_expires_at?->toIso8601String(),
                'current_month_paid' => $currentMonthPaid,
                'payments'           => $payments,
                'payments_year'      => $year,
                'pending_request'    => $pendingRequest ? [
                    'id'             => $pendingRequest->id,
                    'requested_pack' => $pendingRequest->requestedPack?->name,
                    'note'           => $pendingRequest->note,
                    'created_at'     => $pendingRequest->created_at->toIso8601String(),
                ] : null,
            ],
        ]);
    }

    /**
     * Liste des packs actifs (pour choisir un nouveau plan).
     */
    public function packs(Request $request): JsonResponse
    {
        $tenant  = $request->user()->tenant;
        $country = $tenant?->settings['country'] ?? null;
        $profile = $tenant?->profile_type;

        $packs = Pack::with('countryPrices')
            ->where('is_active', true)
            ->when($profile, fn ($q) => $q->where(function ($qq) use ($profile) {
                $qq->whereNull('profile_types')
                   ->orWhereJsonLength('profile_types', 0)
                   ->orWhereJsonContains('profile_types', $profile);
            }))
            ->orderBy('order')
            ->get()
            ->map(function ($p) use ($country) {
                $local = $p->priceFor($country);
                return [
                    'id'          => $p->id,
                    'name'        => $p->name,
                    'description' => $p->description,
                    'price'       => $local['price'],
                    'currency'    => $local['currency'],
                    'period'      => $p->period,
                    'features'    => $p->features,
                ];
            });

        return response()->json([
            'success' => true,
            'packs'   => $packs,
        ]);
    }

    /**
     * Demander un changement de plan (traité manuellement par le super admin).
     */
    public function requestPlanChange(Request $request): JsonResponse
    {
        $request->validate([
            'pack_id' => 'required|exists:packs,id',
            'note'    => 'nullable|string|max:1000',
        ]);

        $user   = $request->user();
        $tenant = $user->tenant;

        abort_if(!$tenant, 404, 'Aucun espace de travail.');
        abort_unless($user->isTenantAdmin(), 403, "Seul l'administrateur de l'espace peut demander un changement de plan.");

        if ((int) $request->pack_id === (int) $tenant->pack_id) {
            return response()->json([
                'success' => false,
                'message' => 'Cet espace utilise déjà ce pack.',
            ], 422);
        }

        $existing = PlanChangeRequest::where('tenant_id', $tenant->id)
            ->where('status', 'pending')
            ->exists();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'Une demande de changement de plan est déjà en attente.',
            ], 422);
        }

        $planRequest = PlanChangeRequest::create([
            'tenant_id'         => $tenant->id,
            'requested_by'      => $user->id,
            'current_pack_id'   => $tenant->pack_id,
            'requested_pack_id' => $request->pack_id,
            'note'              => $request->note,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Votre demande a été envoyée. L\'équipe Qiwam vous contactera rapidement.',
            'request' => $planRequest->load('requestedPack:id,name,price'),
        ], 201);
    }
}
