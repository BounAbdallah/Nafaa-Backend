<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Production extends Model
{
    protected $fillable = [
        'tenant_id', 'product_id', 'bom_id', 'user_id', 'reference', 
        'batch_number', 'expiry_date', 'planned_quantity', 
        'actual_quantity', 'waste_quantity', 'status', 
        'total_cost', 'started_at', 'completed_at'
    ];

    protected $casts = [
        'planned_quantity' => 'float',
        'actual_quantity' => 'float',
        'waste_quantity' => 'float',
        'total_cost' => 'float',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'expiry_date' => 'date',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function generateReference(int $tenantId): string
    {
        $prefix = 'PROD-' . date('Ymd');
        $last = self::where('tenant_id', $tenantId)
                    ->where('reference', 'like', "$prefix%")
                    ->latest()
                    ->first();

        if (!$last) return $prefix . '001';

        $number = (int) substr($last->reference, -3);
        return $prefix . str_pad($number + 1, 3, '0', STR_PAD_LEFT);
    }
}
