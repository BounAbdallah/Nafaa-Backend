<?php

namespace App\Services\Team;

use App\Models\ActivityLog;
use App\Models\Tenant;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TeamService
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
    ) {}

    public function listMembers(Tenant $tenant): \Illuminate\Database\Eloquent\Collection
    {
        return User::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->with('roles')
            ->latest()
            ->get();
    }

    public function invite(Tenant $tenant, array $data, User $invitedBy): array
    {
        $existing = $this->userRepository->findByEmail($data['email']);

        if ($existing && $existing->tenant_id === $tenant->id) {
            throw ValidationException::withMessages([
                'email' => ['Cet utilisateur fait déjà partie de votre équipe.'],
            ]);
        }

        if ($existing && $existing->tenant_id !== null) {
            throw ValidationException::withMessages([
                'email' => ['Cet utilisateur appartient déjà à un autre espace de travail.'],
            ]);
        }

        $tempPassword = $existing ? null : Str::password(12);

        if ($existing) {
            // Rattacher l'utilisateur existant à ce tenant
            $existing->update(['tenant_id' => $tenant->id, 'is_active' => true]);
            $user = $existing;
        } else {
            // Créer un nouveau compte
            $user = $this->userRepository->create([
                'name'              => $data['name'],
                'email'             => $data['email'],
                'password'          => Hash::make($tempPassword),
                'tenant_id'         => $tenant->id,
                'email_verified_at' => now(),
                'is_active'         => true,
                'locale'            => 'fr',
            ]);
        }

        $role = $data['role'] ?? 'employee';
        $user->syncRoles([$role]);

        ActivityLog::record(
            tenantId:   $tenant->id,
            action:     'user.invited',
            userId:     $invitedBy->id,
            subject:    $user,
            properties: ['role' => $role, 'is_new' => !$existing],
        );

        return ['user' => $user->fresh('roles'), 'temp_password' => $tempPassword];
    }

    public function updateRole(Tenant $tenant, User $user, string $role, User $updatedBy): User
    {
        if ($user->tenant_id !== $tenant->id) {
            throw ValidationException::withMessages([
                'user' => ['Cet utilisateur n\'appartient pas à votre espace.'],
            ]);
        }

        if ($user->id === $updatedBy->id) {
            throw ValidationException::withMessages([
                'user' => ['Vous ne pouvez pas modifier votre propre rôle.'],
            ]);
        }

        $oldRole = $user->getRoleNames()->first();
        $user->syncRoles([$role]);

        ActivityLog::record(
            tenantId:   $tenant->id,
            action:     'user.role_changed',
            userId:     $updatedBy->id,
            subject:    $user,
            properties: ['from' => $oldRole, 'to' => $role],
        );

        return $user->fresh('roles');
    }

    public function removeMember(Tenant $tenant, User $user, User $removedBy): void
    {
        if ($user->tenant_id !== $tenant->id) {
            throw ValidationException::withMessages([
                'user' => ['Cet utilisateur n\'appartient pas à votre espace.'],
            ]);
        }

        if ($user->id === $removedBy->id) {
            throw ValidationException::withMessages([
                'user' => ['Vous ne pouvez pas vous retirer vous-même.'],
            ]);
        }

        $user->update(['tenant_id' => null]);
        $user->syncRoles([]);
        $user->tokens()->delete();

        ActivityLog::record(
            tenantId:   $tenant->id,
            action:     'user.removed',
            userId:     $removedBy->id,
            subject:    $user,
            properties: ['name' => $user->name, 'email' => $user->email],
        );
    }

    public function getActivityLogs(Tenant $tenant, int $limit = 20, ?int $userId = null): \Illuminate\Database\Eloquent\Collection
    {
        return ActivityLog::where('tenant_id', $tenant->id)
            ->when($userId, fn($q) => $q->where('user_id', $userId))
            ->with('user')
            ->latest()
            ->limit($limit)
            ->get();
    }
}
