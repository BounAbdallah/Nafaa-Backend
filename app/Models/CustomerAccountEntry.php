<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAccountEntry extends Model
{
    protected $fillable = [
        'tenant_id', 'customer_id', 'order_id', 'user_id',
        'type', 'amount', 'balance_after', 'due_date', 'payment_method', 'note',
    ];

    protected $casts = [
        'amount'        => 'float',
        'balance_after' => 'float',
        'due_date'      => 'date',
    ];

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function order(): BelongsTo    { return $this->belongsTo(Order::class); }
    public function user(): BelongsTo     { return $this->belongsTo(User::class); }

    public const LABELS = [
        'credit'     => 'Vente à crédit',
        'repayment'  => 'Remboursement',
        'deposit'    => 'Dépôt / avance',
        'withdrawal' => 'Achat sur avance',
    ];
}
