<?php

namespace App\Repositories;

use App\Models\Account;
use Illuminate\Support\Facades\Cache;

class AccountRepository
{
    public function findByNumber(int $tenantId, string $number): Account
    {
        // Cache court pour éviter N+1 lors de la comptabilisation en masse
        return Cache::remember("account:{$tenantId}:{$number}", 60, function () use ($tenantId, $number) {
            $account = Account::where('tenant_id', $tenantId)->where('number', $number)->first();

            if (!$account) {
                abort(500, "Compte {$number} introuvable pour ce tenant. Initialisez le plan des comptes.");
            }

            return $account;
        });
    }

    public function clearCache(int $tenantId): void
    {
        // On flush par pattern si Redis, sinon le cache expire naturellement
        Cache::forget("account:{$tenantId}:*");
    }
}
