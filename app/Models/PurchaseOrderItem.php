<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    use HasFactory;
    protected $fillable = [
        'purchase_order_id', 'product_id', 'description',
        'unit', 'quantity', 'unit_price', 'received_quantity',
    ];

    protected $casts = [
        'quantity'          => 'float',
        'unit_price'        => 'float',
        'received_quantity' => 'float',
    ];

    public function purchaseOrder(): BelongsTo { return $this->belongsTo(PurchaseOrder::class); }
    public function product(): BelongsTo        { return $this->belongsTo(Product::class); }

    public function getSubtotalAttribute(): float
    {
        return round($this->quantity * $this->unit_price, 2);
    }
}
