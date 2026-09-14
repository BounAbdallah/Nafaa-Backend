<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Tenant;

class VatService
{
    /**
     * Résolution en cascade : override commande > taux client > taux tenant
     */
    public function resolveRate(?float $requestRate, ?Customer $customer, Tenant $tenant): float
    {
        if ($requestRate !== null) {
            return $requestRate;
        }

        if ($customer && $customer->vat_rate !== null) {
            return (float) $customer->vat_rate;
        }

        return (float) ($tenant->default_vat_rate ?? 0);
    }

    /**
     * Calcule vat_amount à partir du net commercial (après remise)
     */
    public function computeAmount(float $subtotalHt, float $vatRate): float
    {
        return round($subtotalHt * ($vatRate / 100), 2);
    }
}
