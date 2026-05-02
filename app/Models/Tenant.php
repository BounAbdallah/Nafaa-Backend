<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'industry',
        'profile_type',
        'plan',
        'plan_expires_at',
        'is_active',
        'logo',
        'settings',
        'stripe_id',
        'pm_type',
        'pm_last_four',
        'trial_ends_at',
        'owner_id',
        'pack_id',
    ];

    protected $casts = [
        'settings'        => 'array',
        'is_active'       => 'boolean',
        'plan_expires_at' => 'datetime',
        'trial_ends_at'   => 'datetime',
    ];

    protected $hidden = [
        'stripe_id',
    ];

    // Profile types
    const PROFILE_MANUFACTURER = 'manufacturer';
    const PROFILE_RESELLER     = 'reseller';
    const PROFILE_WHOLESALER   = 'wholesaler';
    const PROFILE_SERVICE      = 'service_provider';

    // Plans
    const PLAN_DEMARRAGE  = 'demarrage';
    const PLAN_PRO        = 'pro';
    const PLAN_BUSINESS   = 'business';
    const PLAN_ENTREPRISE = 'entreprise';

    // Industries for Francophone Africa
    const INDUSTRIES = [
        'agriculture'   => 'Agriculture & Agroalimentaire',
        'commerce'      => 'Commerce & Distribution',
        'construction'  => 'Construction & BTP',
        'education'     => 'Éducation & Formation',
        'energie'       => 'Énergie & Environnement',
        'finance'       => 'Finance & Assurance',
        'health'        => 'Santé & Pharmacie',
        'hospitality'   => 'Hôtellerie & Restauration',
        'ict'           => 'Technologie & Numérique',
        'manufacturing' => 'Industrie & Fabrication',
        'media'         => 'Médias & Communication',
        'real_estate'   => 'Immobilier',
        'retail'        => 'Commerce de détail',
        'services'      => 'Services aux entreprises',
        'transport'     => 'Transport & Logistique',
        'other'         => 'Autre',
    ];

    public function pack(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Pack::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function owner(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function isOnTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    public function hasActivePlan(): bool
    {
        if ($this->plan === self::PLAN_DEMARRAGE) {
            return true;
        }

        return $this->plan_expires_at && $this->plan_expires_at->isFuture();
    }

    public function getPlanLimits(): array
    {
        if ($this->pack) {
            return $this->pack->limits;
        }

        return match ($this->plan) {
            self::PLAN_DEMARRAGE  => ['users' => 2,  'products' => 50,   'storage_gb' => 1],
            self::PLAN_PRO        => ['users' => 10, 'products' => 500,  'storage_gb' => 10],
            self::PLAN_BUSINESS   => ['users' => 50, 'products' => 5000, 'storage_gb' => 50],
            self::PLAN_ENTREPRISE => ['users' => -1, 'products' => -1,   'storage_gb' => 500],
            default               => ['users' => 2,  'products' => 50,   'storage_gb' => 1],
        };
    }
}
