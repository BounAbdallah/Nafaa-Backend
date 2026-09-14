<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Tenant;
use App\Models\User;

/**
 * Restreint les données admin au pays de l'admin connecté.
 * Un super_admin voit tout ; un country_admin ne voit que les espaces
 * dont settings->country correspond à son country_code.
 */
trait ScopesByCountry
{
    /** L'utilisateur est-il un admin restreint à un pays ? */
    protected function isCountryAdmin(User $user): bool
    {
        return $user->hasRole('country_admin') && !$user->hasRole('super_admin');
    }

    /**
     * Pays effectif du filtre :
     * - country_admin : toujours son pays (verrouillé)
     * - super_admin   : le paramètre ?country=XX s'il est fourni (filtre volontaire)
     */
    protected function effectiveCountry(User $user): ?string
    {
        if ($this->isCountryAdmin($user) && $user->country_code) {
            return $user->country_code;
        }

        if ($country = request('country')) {
            return strtoupper($country);
        }

        return null;
    }

    /** Applique le filtre pays à une requête sur la table tenants. */
    protected function scopeTenantsByCountry($query, User $user)
    {
        if ($country = $this->effectiveCountry($user)) {
            $query->where('settings->country', $country);
        }

        return $query;
    }

    /** Applique le filtre pays à une requête sur la table users (via leur tenant). */
    protected function scopeUsersByCountry($query, User $user)
    {
        if ($country = $this->effectiveCountry($user)) {
            $query->whereHas('tenant', fn ($q) => $q->where('settings->country', $country));
        }

        return $query;
    }

    /** Interdit l'accès à un tenant hors du pays de l'admin. */
    protected function assertCanManageTenant(User $user, Tenant $tenant): void
    {
        if (!$this->isCountryAdmin($user)) return;

        $tenantCountry = $tenant->settings['country'] ?? null;

        abort_if(
            $tenantCountry !== $user->country_code,
            403,
            'Cet espace n\'appartient pas à votre pays.'
        );
    }

    /** Interdit l'accès à un utilisateur hors du pays de l'admin. */
    protected function assertCanManageUser(User $admin, User $target): void
    {
        if (!$this->isCountryAdmin($admin)) return;

        $tenantCountry = $target->tenant?->settings['country'] ?? null;

        abort_if(
            $tenantCountry !== $admin->country_code,
            403,
            'Cet utilisateur n\'appartient pas à votre pays.'
        );
    }
}
