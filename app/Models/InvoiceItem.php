<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    public $timestamps = false;
    protected $fillable = ['invoice_id','description','quantity','unit_price','total','sort_order'];

    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
}
