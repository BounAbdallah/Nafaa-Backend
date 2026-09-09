<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class Invoice extends Model
{
    protected $fillable = [
        'tenant_id','customer_id','quote_id','reference','title','content',
        'issued_at','due_at','status','subtotal','tax_rate','tax_amount',
        'discount','total','currency','paid_at','notes','terms',
    ];
    protected $casts = ['issued_at' => 'date', 'due_at' => 'date', 'paid_at' => 'date'];

    public function tenant(): BelongsTo   { return $this->belongsTo(Tenant::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function quote(): BelongsTo    { return $this->belongsTo(Quote::class); }
    public function items(): HasMany      { return $this->hasMany(InvoiceItem::class)->orderBy('sort_order'); }

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
