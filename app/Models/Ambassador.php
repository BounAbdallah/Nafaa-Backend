<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Ambassador extends Model
{
    protected $fillable = [
        'user_id', 'referral_code', 'commission_rate',
        'status', 'total_earnings', 'total_referrals', 'active_referrals', 'notes',
    ];

    protected $casts = [
        'commission_rate' => 'decimal:2',
        'total_earnings'  => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(AmbassadorReferral::class);
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }

    /** Génère un code unique de 8 caractères */
    public static function generateCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (self::where('referral_code', $code)->exists());

        return $code;
    }

    /** URL du lien de parrainage */
    public function getReferralUrlAttribute(): string
    {
        return config('app.frontend_url', 'http://localhost:3000') . '/inscription?ref=' . $this->referral_code;
    }

    /** Recalcule les compteurs */
    public function recalculate(): void
    {
        $this->total_referrals  = $this->referrals()->count();
        $this->active_referrals = $this->referrals()->where('status', 'active')->count();
        $this->total_earnings   = $this->referrals()->where('status', 'active')->sum('commission_amount');
        $this->save();
    }
}
