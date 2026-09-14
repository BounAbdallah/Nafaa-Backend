<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'name', 'email', 'phone', 'company',
        'type', 'address', 'city', 'country', 'notes',
        'total_spent', 'orders_count', 'is_active', 'account_balance',
        'vat_rate',
    ];

    protected $casts = [
        'total_spent'     => 'float',
        'account_balance' => 'float',
        'orders_count'    => 'integer',
        'is_active'       => 'boolean',
        'vat_rate'        => 'float',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function orders(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function accountEntries(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CustomerAccountEntry::class)->latest();
    }

    /** Montant que le client DOIT à la boutique (ardoise). 0 s'il n'a pas de dette. */
    public function getDebtAttribute(): float
    {
        return $this->account_balance < 0 ? abs($this->account_balance) : 0.0;
    }

    /** Avance disponible du client (dépôt restant). 0 s'il n'a pas d'avance. */
    public function getDepositAttribute(): float
    {
        return $this->account_balance > 0 ? (float) $this->account_balance : 0.0;
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->company
            ? "{$this->name} ({$this->company})"
            : $this->name;
    }

    public static function countries(): array
    {
        return [
            'SN' => 'Sénégal',
            'CI' => 'Côte d\'Ivoire',
            'ML' => 'Mali',
            'BF' => 'Burkina Faso',
            'GN' => 'Guinée',
            'CM' => 'Cameroun',
            'TG' => 'Togo',
            'BJ' => 'Bénin',
            'NE' => 'Niger',
            'MR' => 'Mauritanie',
            'GA' => 'Gabon',
            'CD' => 'RD Congo',
            'MG' => 'Madagascar',
            'FR' => 'France',
            'MA' => 'Maroc',
        ];
    }
}
