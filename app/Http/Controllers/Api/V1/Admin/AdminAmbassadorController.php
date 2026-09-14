<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ambassador;
use App\Models\User;
use App\Notifications\AmbassadorWelcomeNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class AdminAmbassadorController extends Controller
{
    /** Liste tous les ambassadeurs */
    public function index(): JsonResponse
    {
        $ambassadors = Ambassador::with('user')
            ->withCount(['referrals', 'referrals as active_referrals_count' => fn($q) => $q->where('status', 'active')])
            ->latest()
            ->get()
            ->map(fn($a) => $this->formatAmbassador($a));

        return response()->json(['success' => true, 'data' => $ambassadors]);
    }

    /** Créer un ambassadeur + son compte utilisateur */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'            => 'required|string|max:255',
            'email'           => 'required|email|unique:users,email',
            'commission_rate' => 'required|numeric|min:1|max:100',
            'notes'           => 'nullable|string|max:1000',
        ]);

        // Générer un mot de passe temporaire
        $tempPassword = Str::upper(Str::random(3)) . rand(100, 999) . Str::lower(Str::random(3));

        // Créer l'utilisateur
        $user = User::create([
            'name'                 => $request->name,
            'email'                => $request->email,
            'password'             => Hash::make($tempPassword),
            'is_active'            => true,
            'force_password_change'=> true,
            'email_verified_at'    => now(), // auto-verified
        ]);

        // Assigner le rôle ambassador
        $role = Role::firstOrCreate(['name' => 'ambassador', 'guard_name' => 'sanctum']);
        $user->assignRole($role);

        // Créer le profil ambassadeur
        $ambassador = Ambassador::create([
            'user_id'         => $user->id,
            'referral_code'   => Ambassador::generateCode(),
            'commission_rate' => $request->commission_rate,
            'notes'           => $request->notes,
        ]);

        // Envoyer l'email de bienvenue
        try {
            $user->notify(new AmbassadorWelcomeNotification($ambassador, $tempPassword));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Ambassador welcome email failed: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => "Compte ambassadeur créé. Un email a été envoyé à {$user->email}.",
            'data'    => $this->formatAmbassador($ambassador->load('user')),
        ], 201);
    }

    /** Modifier la commission ou le statut */
    public function update(Request $request, Ambassador $ambassador): JsonResponse
    {
        $request->validate([
            'commission_rate' => 'sometimes|numeric|min:1|max:100',
            'status'          => 'sometimes|in:active,inactive',
            'notes'           => 'nullable|string|max:1000',
        ]);

        $ambassador->update($request->only('commission_rate', 'status', 'notes'));

        return response()->json([
            'success' => true,
            'message' => 'Ambassadeur mis à jour.',
            'data'    => $this->formatAmbassador($ambassador->load('user')),
        ]);
    }

    /** Détail d'un ambassadeur avec ses filleuls */
    public function show(Ambassador $ambassador): JsonResponse
    {
        $ambassador->load(['user', 'referrals' => fn($q) => $q->latest()]);

        return response()->json([
            'success' => true,
            'data'    => array_merge($this->formatAmbassador($ambassador), [
                'referrals' => $ambassador->referrals->map(fn($r) => [
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
                ]),
            ]),
        ]);
    }

    /** Marquer une commission comme payée */
    public function markPaid(Request $request, Ambassador $ambassador): JsonResponse
    {
        $request->validate(['referral_id' => 'required|exists:ambassador_referrals,id']);

        $referral = $ambassador->referrals()->findOrFail($request->referral_id);
        $referral->update(['commission_paid' => true]);

        return response()->json(['success' => true, 'message' => 'Commission marquée comme payée.']);
    }

    /** Supprimer un ambassadeur */
    public function destroy(Ambassador $ambassador): JsonResponse
    {
        $ambassador->user->delete();
        return response()->json(['success' => true]);
    }

    private function formatAmbassador(Ambassador $a): array
    {
        return [
            'id'               => $a->id,
            'user_id'          => $a->user_id,
            'name'             => $a->user?->name,
            'email'            => $a->user?->email,
            'referral_code'    => $a->referral_code,
            'referral_url'     => $a->referral_url,
            'commission_rate'  => (float) $a->commission_rate,
            'status'           => $a->status,
            'total_earnings'   => (float) $a->total_earnings,
            'total_referrals'  => $a->total_referrals,
            'active_referrals' => $a->active_referrals,
            'notes'            => $a->notes,
            'created_at'       => $a->created_at->format('d/m/Y'),
        ];
    }
}
