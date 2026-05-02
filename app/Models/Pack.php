<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pack extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'price',
        'period',
        'features',
        'limits',
        'is_active',
        'order',
    ];

    protected $casts = [
        'features'  => 'array',
        'limits'    => 'array',
        'is_active' => 'boolean',
        'price'     => 'decimal:2',
    ];
}
