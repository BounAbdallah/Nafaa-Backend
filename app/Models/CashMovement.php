<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CashMovement extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'user_id', 'type', 'label',
        'amount', 'payment_method', 'movement_date', 'notes',
    ];

    protected $casts = [
        'amount'        => 'float',
        'movement_date' => 'date',
    ];

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function user(): BelongsTo   { return $this->belongsTo(User::class); }

    public static function types(): array
    {
        return [
            'income'       => 'Autre recette',
            'withdrawal'   => 'Retrait patron',
            'contribution' => 'Apport / Capital',
        ];
    }

    /** Les types qui augmentent la trésorerie (signe +). */
    public static function incomeTypes(): array
    {
        return ['income', 'contribution'];
    }

    /** Les types qui diminuent la trésorerie (signe -). */
    public static function outflowTypes(): array
    {
        return ['withdrawal'];
    }
}
