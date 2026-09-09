<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackCountryPrice extends Model
{
    protected $fillable = [
        'pack_id',
        'country_code',
        'price',
        'currency',
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    public function pack(): BelongsTo
    {
        return $this->belongsTo(Pack::class);
    }
}
