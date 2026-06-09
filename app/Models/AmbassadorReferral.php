<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AmbassadorReferral extends Model
{
    protected $fillable = [
        'ambassador_id', 'tenant_id', 'client_name', 'client_email',
        'subscription_plan', 'subscription_amount', 'commission_rate',
        'commission_amount', 'status', 'commission_paid', 'activated_at',
    ];

    protected $casts = [
        'subscription_amount' => 'decimal:2',
        'commission_rate'     => 'decimal:2',
        'commission_amount'   => 'decimal:2',
        'commission_paid'     => 'boolean',
        'activated_at'        => 'datetime',
    ];

    public function ambassador(): BelongsTo
    {
        return $this->belongsTo(Ambassador::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
