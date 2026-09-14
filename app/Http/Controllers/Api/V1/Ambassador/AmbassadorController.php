<?php

namespace App\Http\Controllers\Api\V1\Ambassador;

use App\Http\Controllers\Controller;
use App\Models\Ambassador;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AmbassadorController extends Controller
{
    /** Dashboard — stats + referrals */
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $ambassador = Ambassador::with(['referrals' => fn($q) => $q->latest()])
            ->where('user_id', $user->id)
            ->firstOrFail();

        $referrals = $ambassador->referrals->map(fn($r) => [
            'id'                  => $r->id,
            'client_name'         => $r->client_name,
            'client_email'        => $r->client_email,
            'status'              => $r->status,
            'subscription_plan'   => $r->subscription_plan,
            'subscription_amount' => (float) $r->subscription_amount,
            'commission_amount'   => (float) $r->commission_amount,
            'commission_paid'     => $r->commission_paid,
            'activated_at'        => $r->activated_at?->format('d/m/Y'),
            'created_at'          => $r->created_at->format('d/m/Y'),
        ]);

        $pendingEarnings = $ambassador->referrals()
            ->where('status', 'active')
            ->where('commission_paid', false)
            ->sum('commission_amount');

        return response()->json([
            'success' => true,
            'data'    => [
                'name'              => $user->name,
                'email'             => $user->email,
                'referral_code'     => $ambassador->referral_code,
                'referral_url'      => $ambassador->referral_url,
                'commission_rate'   => (float) $ambassador->commission_rate,
                'status'            => $ambassador->status,
                'total_earnings'    => (float) $ambassador->total_earnings,
                'pending_earnings'  => (float) $pendingEarnings,
                'total_referrals'   => $ambassador->total_referrals,
                'active_referrals'  => $ambassador->active_referrals,
                'force_password_change' => $user->force_password_change,
                'referrals'         => $referrals,
            ],
        ]);
    }

    /** Changer le mot de passe (première connexion) */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = $request->user();
        $user->update([
            'password'              => Hash::make($request->password),
            'force_password_change' => false,
        ]);

        return response()->json(['success' => true, 'message' => 'Mot de passe mis à jour avec succès.']);
    }
}
