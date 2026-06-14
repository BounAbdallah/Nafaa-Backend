<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catalogue de produits partagé, alimenté par les admins (par pays).
 * Sert à pré-remplir le formulaire produit d'un commerçant lors d'un scan
 * de code-barres non reconnu par les bases internationales.
 */
class CatalogProduct extends Model
{
    protected $fillable = [
        'barcode', 'country_code', 'name', 'brand',
        'category', 'default_unit', 'image', 'created_by',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
