<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'name', 'sku', 'description', 'type',
        'category', 'unit', 'selling_price', 'cost_price',
        'stock_quantity', 'stock_alert', 'image', 'is_active',
    ];

    protected $casts = [
        'selling_price'  => 'float',
        'cost_price'     => 'float',
        'stock_quantity' => 'integer',
        'stock_alert'    => 'integer',
        'is_active'      => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isLowStock(): bool
    {
        return $this->type === 'product' && $this->stock_quantity <= $this->stock_alert;
    }

    public function getMarginAttribute(): float
    {
        if ($this->selling_price <= 0) return 0;
        return round((($this->selling_price - $this->cost_price) / $this->selling_price) * 100, 1);
    }

    // Catégories disponibles
    public static function categories(): array
    {
        return [
            'alimentaire'   => 'Alimentaire',
            'textile'       => 'Textile & Habillement',
            'electronique'  => 'Électronique',
            'informatique'  => 'Informatique',
            'mobilier'      => 'Mobilier & Décoration',
            'cosmetique'    => 'Cosmétique & Beauté',
            'sante'         => 'Santé & Pharmacie',
            'construction'  => 'Construction & BTP',
            'agriculture'   => 'Agriculture',
            'transport'     => 'Transport & Logistique',
            'services'      => 'Services',
            'autre'         => 'Autre',
        ];
    }

    public static function units(): array
    {
        return ['pièce', 'kg', 'g', 'litre', 'cl', 'ml', 'm', 'cm', 'm²', 'm³', 'boîte', 'carton', 'sac', 'heure', 'jour', 'forfait'];
    }
}
