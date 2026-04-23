<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use SoftDeletes, HasFactory;

    protected $fillable = [
        'tenant_id', 'user_id', 'category', 'description',
        'amount', 'payment_method', 'expense_date', 'notes',
    ];

    protected $casts = [
        'amount'       => 'float',
        'expense_date' => 'date',
    ];

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function user(): BelongsTo   { return $this->belongsTo(User::class); }

    public static function categories(): array
    {
        return [
            'loyer'         => 'Loyer & Bail',
            'electricite'   => 'Électricité',
            'eau'           => 'Eau',
            'salaires'      => 'Salaires & Charges',
            'transport'     => 'Transport & Livraison',
            'marketing'     => 'Marketing & Publicité',
            'fournitures'   => 'Fournitures & Matériel',
            'maintenance'   => 'Maintenance & Réparations',
            'communication' => 'Téléphone & Internet',
            'taxes'         => 'Taxes & Impôts',
            'autre'         => 'Autre',
        ];
    }

    public static function paymentMethods(): array
    {
        return [
            'cash'          => 'Espèces',
            'wave'          => 'Wave',
            'orange_money'  => 'Orange Money',
            'mtn_momo'      => 'MTN MoMo',
            'bank_transfer' => 'Virement bancaire',
            'cheque'        => 'Chèque',
        ];
    }
}
