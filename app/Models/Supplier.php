<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use HasFactory;
    protected $fillable = [
        'tenant_id', 'name', 'phone', 'email', 'contact_name',
        'address', 'city', 'country', 'notes',
        'total_ordered', 'orders_count', 'is_active',
    ];

    protected $casts = [
        'total_ordered' => 'float',
        'orders_count'  => 'integer',
        'is_active'     => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public static function countries(): array
    {
        return Customer::countries();
    }
}
