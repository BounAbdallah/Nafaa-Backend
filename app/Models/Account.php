<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    protected $fillable = [
        'tenant_id', 'number', 'label', 'class', 'nature', 'is_system', 'is_active',
    ];

    protected $casts = [
        'class'     => 'integer',
        'is_system' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function tenant(): BelongsTo       { return $this->belongsTo(Tenant::class); }
    public function lines(): HasMany          { return $this->hasMany(JournalEntryLine::class); }

    public static function classLabel(int $class): string
    {
        return match($class) {
            1 => 'Ressources durables',
            2 => 'Actif immobilisé',
            3 => 'Stocks',
            4 => 'Tiers',
            5 => 'Trésorerie',
            6 => 'Charges',
            7 => 'Produits',
            default => "Classe $class",
        };
    }

    /** Solde normal : débiteur (asset/expense/stock/treasury) ou créditeur (liability/equity/revenue). */
    public function normalBalanceIsDebit(): bool
    {
        return in_array($this->nature, ['asset', 'expense', 'stock', 'treasury']);
    }
}
