<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pack extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'price',
        'period',
        'features',
        'profile_types',
        'limits',
        'is_active',
        'order',
    ];

    protected $casts = [
        'features'  => 'array',
        'profile_types' => 'array',
        'limits'    => 'array',
        'is_active' => 'boolean',
        'price'     => 'decimal:2',
    ];

    public function countryPrices(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PackCountryPrice::class);
    }

    /**
     * Prix et devise du pack pour un pays donné.
     * Retourne le prix personnalisé du pays s'il existe, sinon le prix de base (XOF).
     */
    public function priceFor(?string $countryCode): array
    {
        if ($countryCode) {
            $override = $this->countryPrices
                ->firstWhere('country_code', strtoupper($countryCode));

            if ($override) {
                return [
                    'price'       => (float) $override->price,
                    'currency'    => $override->currency,
                    'is_override' => true,
                ];
            }
        }

        return [
            'price'       => (float) $this->price,
            'currency'    => 'XOF',
            'is_override' => false,
        ];
    }
}
