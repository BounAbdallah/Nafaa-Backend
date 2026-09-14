<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    protected $fillable = [
        'tenant_id','customer_id','title','description','location',
        'start_at','end_at','status','color','notes',
    ];
    protected $casts = ['start_at' => 'datetime', 'end_at' => 'datetime'];

    public function tenant(): BelongsTo   { return $this->belongsTo(Tenant::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
}
