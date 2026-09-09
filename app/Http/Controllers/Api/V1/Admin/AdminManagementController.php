<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Gestion des administrateurs plateforme (réservé au super admin).
 * Un « country_admin » gère uniquement les utilisateurs et abonnements
 * des espaces de son pays (settings->country).
 */
class AdminManagementController extends Controller
{
    private function adminPayload(User $u): array
    {
        return [
            'id'            => $u->id,
            'name'          => $u->name,
            'email'         => $u->email,
            'phone'         => $u->phone,
            'country_code'  => $u->country_code,
            'is_active'     => (bool) $u->is_active,
            'block_reason'  => $u->block_reason,
            'last_login_at' => $u->last_login_at?->toIso8601String(),
            'created_at'    => $u->created_at->toIso8601String(),
        ];
    }

    /** Liste des admins pays. */
    public function index(): JsonResponse
    {
        Role::firstOrCreate(['name' => 'country_admin', 'guard_name' => 'sanctum']);

        $admins = User::role('country_admin')
            ->orderBy('country_code')
            ->get();

        return response()->json([
            'success' => true,
            'admins'  => $admins->map(fn ($u) => $this->adminPayload($u)),
        ]);
    }

    /** Détail complet d'un admin pays (infos + dernières connexions). */
    public function show(User $admin): JsonResponse
    {
        abort_unless($admin->hasRole('country_admin'), 404);

        $logins = \App\Models\ActivityLog::where('action', 'login')
            ->where('user_id', $admin->id)
            ->latest()
            ->limit(15)
            ->get()
            ->map(fn ($log) => [
                'id'         => $log->id,
                'ip_address' => $log->ip_address,
                'user_agent' => $log->properties['user_agent'] ?? null,
                'created_at' => $log->created_at->toIso8601String(),
            ]);

        $totalLogins = \App\Models\ActivityLog::where('action', 'login')
            ->where('user_id', $admin->id)
            ->count();

        return response()->json([
            'success' => true,
            'admin'   => $this->adminPayload($admin),
            'logins'  => $logins,
            'total_logins' => $totalLogins,
        ]);
    }

    /** Créer un admin pays. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'         => 'required|string|max:255',
            'email'        => 'required|email|unique:users,email',
            'password'     => 'required|string|min:8',
            'phone'        => 'nullable|string|max:30',
            'country_code' => 'required|string|max:5',
        ]);

        Role::firstOrCreate(['name' => 'country_admin', 'guard_name' => 'sanctum']);

        $admin = User::create([
            'name'              => $data['name'],
            'email'             => $data['email'],
            'password'          => Hash::make($data['password']),
            'phone'             => $data['phone'] ?? null,
            'country_code'      => strtoupper($data['country_code']),
            'is_active'         => true,
            'email_verified_at' => now(), // compte interne — pas de vérification e-mail
        ]);

        $admin->assignRole('country_admin');

        return response()->json([
            'success' => true,
            'message' => "Admin « {$admin->name} » créé pour le pays {$admin->country_code}.",
            'admin'   => $this->adminPayload($admin),
        ], 201);
    }

    /** Modifier un admin (nom, pays, mot de passe). */
    public function update(Request $request, User $admin): JsonResponse
    {
        abort_unless($admin->hasRole('country_admin'), 404);

        $data = $request->validate([
            'name'         => 'sometimes|string|max:255',
            'phone'        => 'nullable|string|max:30',
            'country_code' => 'sometimes|string|max:5',
            'password'     => 'nullable|string|min:8',
        ]);

        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        if (isset($data['country_code'])) {
            $data['country_code'] = strtoupper($data['country_code']);
        }

        $admin->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Admin mis à jour.',
            'admin'   => $this->adminPayload($admin->fresh()),
        ]);
    }

    /** Bloquer un admin. */
    public function block(Request $request, User $admin): JsonResponse
    {
        abort_unless($admin->hasRole('country_admin'), 404);

        $admin->update([
            'is_active'    => false,
            'block_reason' => $request->get('reason') ?: 'Bloqué par le super administrateur.',
        ]);

        // Révoquer ses sessions
        $admin->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => "Admin « {$admin->name} » bloqué.",
        ]);
    }

    /** Débloquer un admin. */
    public function unblock(User $admin): JsonResponse
    {
        abort_unless($admin->hasRole('country_admin'), 404);

        $admin->update(['is_active' => true, 'block_reason' => null]);

        return response()->json([
            'success' => true,
            'message' => "Admin « {$admin->name} » débloqué.",
        ]);
    }

    /** Supprimer définitivement un compte admin. */
    public function destroy(User $admin): JsonResponse
    {
        abort_unless($admin->hasRole('country_admin'), 404);

        $admin->tokens()->delete();
        $name = $admin->name;
        $admin->delete();

        return response()->json([
            'success' => true,
            'message' => "Compte admin « {$name} » supprimé.",
        ]);
    }
}
