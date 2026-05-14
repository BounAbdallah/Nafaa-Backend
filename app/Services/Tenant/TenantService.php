<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AccountCreatedNotification;
use App\Repositories\Contracts\TenantRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class TenantService
{
    public function __construct(
        private readonly TenantRepositoryInterface $tenantRepository,
        private readonly UserRepositoryInterface $userRepository,
    ) {}

    public function createTenant(User $user, array $data): Tenant
    {
        return DB::transaction(function () use ($user, $data) {
            $slug = $data['slug'] ?? $this->tenantRepository->generateUniqueSlug($data['name']);

            $tenant = $this->tenantRepository->create([
                'name'         => $data['name'],
                'slug'         => $slug,
                'industry'     => $data['industry'],
                'profile_type' => $data['profile_type'],
                'plan'         => $data['plan'] ?? Tenant::PLAN_DEMARRAGE,
                'pack_id'      => $data['pack_id'] ?? null,
                'owner_id'     => $user->id,
                'is_active'    => false, // Pending Super Admin approval
            ]);

            // Store country, currency and phone in settings at creation
            $initialSettings = $tenant->settings ?? [];
            if (!empty($data['country']))  $initialSettings['country']  = $data['country'];
            if (!empty($data['currency'])) $initialSettings['currency'] = $data['currency'];
            if (!empty($data['phone']))    $initialSettings['phone']    = $data['phone'];
            if (!empty($initialSettings)) {
                $tenant->settings = $initialSettings;
                $tenant->save();
            }

            if (isset($data['pack_id'])) {
                $pack = \App\Models\Pack::find($data['pack_id']);
                if ($pack) {
                    $packUpdates = ['plan' => $pack->slug];

                    // Auto-apply pack features as enabled_modules immediately at creation
                    if (!empty($pack->features)) {
                        $currentSettings = $tenant->settings ?? [];
                        $currentSettings['enabled_modules'] = $pack->features;
                        $packUpdates['settings'] = $currentSettings;
                    }

                    $tenant->update($packUpdates);
                }
            }

            $this->userRepository->update($user, ['tenant_id' => $tenant->id]);

            $this->assignTenantAdminRole($user, $tenant);

            // 1. Envoyer l'e-mail de vérification (différé jusqu'ici)
            try {
                $user->sendEmailVerificationNotification();
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning('Failed to send verification email: ' . $e->getMessage());
            }

            // 2. Confirmer la création du compte à l'utilisateur
            try {
                $user->notify(new AccountCreatedNotification($tenant->fresh()));
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning('Failed to send account created notification: ' . $e->getMessage());
            }

            // 3. Notifier les super admins
            try {
                $superAdmins = User::role('super_admin')->get();
                \Illuminate\Support\Facades\Notification::send($superAdmins, new \App\Notifications\TenantCreatedNotification($tenant));
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning('Failed to notify super admins of new tenant: ' . $e->getMessage());
            }

            return $tenant;
        });
    }

    private function assignTenantAdminRole(User $user, Tenant $tenant): void
    {
        $roleName = 'admin';

        if (! Role::where('name', $roleName)->where('guard_name', 'sanctum')->exists()) {
            Role::create(['name' => $roleName, 'guard_name' => 'sanctum']);
        }

        $user->assignRole($roleName);
    }

    public function getCurrentTenant(User $user): ?Tenant
    {
        return $user->tenant;
    }

    public function updateTenant(Tenant $tenant, array $data): Tenant
    {
        return $this->tenantRepository->update($tenant, $data);
    }
}
