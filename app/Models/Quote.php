<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class Quote extends Model
{
    protected $fillable = [
        'tenant_id','customer_id','reference','title','content',
        'issued_at','expires_at','status','subtotal','tax_rate',
        'tax_amount','discount','total','currency','notes','terms',
    ];
    protected $casts = ['issued_at' => 'date', 'expires_at' => 'date'];

    public function tenant(): BelongsTo   { return $this->belongsTo(Tenant::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function items(): HasMany      { return $this->hasMany(QuoteItem::class)->orderBy('sort_order'); }
    public function invoices(): HasMany   { return $this->hasMany(Invoice::class); }
    public function contracts(): HasMany  { return $this->hasMany(Contract::class); }

    public function recalculate(): void
    {
        $subtotal = $this->items()->sum(\DB::raw('quantity * unit_price'));
        $tax      = round($subtotal * ($this->tax_rate / 100), 2);
        $this->update([
            'subtotal'   => $subtotal,
            'tax_amount' => $tax,
            'total'      => $subtotal + $tax - $this->discount,
        ]);
    }
}
