<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends Model
{
    use SoftDeletes, HasFactory;

    protected $fillable = [
        'tenant_id', 'supplier_id', 'user_id', 'reference', 'status',
        'order_date', 'expected_date', 'received_date',
        'total_amount', 'notes',
    ];

    protected $casts = [
        'order_date'    => 'date',
        'expected_date' => 'date',
        'received_date' => 'date',
        'total_amount'  => 'float',
    ];

    public static array $statuses = [
        'draft'      => 'Brouillon',
        'ordered'    => 'Commandé',
        'in_transit' => 'En transit',
        'partial'    => 'Reçu partiellement',
        'received'   => 'Reçu totalement',
        'cancelled'  => 'Annulé',
    ];

    public function tenant(): BelongsTo    { return $this->belongsTo(Tenant::class); }
    public function supplier(): BelongsTo  { return $this->belongsTo(Supplier::class); }
    public function user(): BelongsTo      { return $this->belongsTo(User::class); }
    public function items(): HasMany       { return $this->hasMany(PurchaseOrderItem::class); }

    public function recalculateTotal(): void
    {
        $this->total_amount = $this->items()->sum(\DB::raw('quantity * unit_price'));
        $this->save();
    }

    public static function generateReference(int $tenantId): string
    {
        $year  = now()->year;
        $count = static::where('tenant_id', $tenantId)
                        ->whereYear('created_at', $year)
                        ->withTrashed()
                        ->count() + 1;

        return sprintf('CMD-%d-%04d', $year, $count);
    }
}
