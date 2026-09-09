<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contract extends Model
{
    protected $fillable = [
        'tenant_id','customer_id','quote_id','reference','title','content',
        'signed_at','starts_at','ends_at','status','value','currency','notes',
    ];
    protected $casts = [
        'signed_at' => 'date', 'starts_at' => 'date', 'ends_at' => 'date',
    ];

    public function tenant(): BelongsTo   { return $this->belongsTo(Tenant::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function quote(): BelongsTo    { return $this->belongsTo(Quote::class); }
}
