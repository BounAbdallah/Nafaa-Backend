<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'user_id',
        'reference',
        'status',
        'payment_status',
        'payment_method',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'total_amount',
        'paid_amount',
        'change_amount',
        'notes',
    ];

    protected $casts = [
        'subtotal'        => 'float',
        'tax_amount'      => 'float',
        'discount_amount' => 'float',
        'total_amount'    => 'float',
        'paid_amount'     => 'float',
        'change_amount'   => 'float',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted()
    {
        static::created(function ($order) {
            if ($order->customer_id) {
                $order->customer->increment('orders_count');
                $order->customer->increment('total_spent', $order->total_amount);
            }
        });

        static::deleted(function ($order) {
            if ($order->customer_id) {
                $order->customer->decrement('orders_count');
                $order->customer->decrement('total_spent', $order->total_amount);
            }
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class);
    }
}
