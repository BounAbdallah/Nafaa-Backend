<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'name', 'email', 'phone', 'company',
        'type', 'address', 'city', 'country', 'notes',
        'total_spent', 'orders_count', 'is_active',
    ];

    protected $casts = [
        'total_spent'  => 'float',
        'orders_count' => 'integer',
        'is_active'    => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function orders(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->company
            ? "{$this->name} ({$this->company})"
            : $this->name;
    }

    public static function countries(): array
    {
        return [
            'SN' => 'Sénégal',
            'CI' => 'Côte d\'Ivoire',
            'ML' => 'Mali',
            'BF' => 'Burkina Faso',
            'GN' => 'Guinée',
            'CM' => 'Cameroun',
            'TG' => 'Togo',
            'BJ' => 'Bénin',
            'NE' => 'Niger',
            'MR' => 'Mauritanie',
            'GA' => 'Gabon',
            'CD' => 'RD Congo',
            'MG' => 'Madagascar',
            'FR' => 'France',
            'MA' => 'Maroc',
        ];
    }
}
