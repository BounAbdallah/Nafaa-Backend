<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Normalise un chemin/URL d'image vers une URL publique valide.
     * Corrige les anciennes URLs localhost stockées en base (ex: http://localhost/storage/products/xxx.png).
     */
    public static function resolveStorageUrl(?string $path): ?string
    {
        if (!$path || $path === '0' || $path === 'false') return null;

        // URL absolue avec un domaine différent de localhost → déjà correcte
        if (str_starts_with($path, 'http') && !preg_match('#https?://localhost[:/]#', $path)) {
            return $path;
        }

        // URL localhost (ancienne) → extraire le chemin relatif depuis /storage/
        if (preg_match('#/storage/(.+)$#', $path, $m)) {
            return \Illuminate\Support\Facades\Storage::disk('public')->url($m[1]);
        }

        // Chemin relatif stocké directement (cas normal après fix)
        return \Illuminate\Support\Facades\Storage::disk('public')->url($path);
    }

    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'sku'            => $this->sku,
            'description'    => $this->description,
            'type'           => $this->type,
            'type_label'     => $this->type === 'service' ? 'Service' : ($this->type === 'material' ? 'Matière Première' : 'Produit'),
            'category_id'    => $this->category_id,
            'category_data'  => $this->category_id ? [
                'id'    => $this->category_id,
                'name'  => $this->getRelation('category')?->name,
                'color' => $this->getRelation('category')?->color,
            ] : null,
            'category'       => $this->getRawOriginal('category'),
            'category_label' => $this->category_id
                ? $this->getRelation('category')?->name
                : (\App\Models\Product::categories()[$this->getRawOriginal('category') ?? ''] ?? $this->getRawOriginal('category')),
            'unit'           => $this->unit,
            'selling_price'  => $this->selling_price,
            'min_price'      => $this->min_price,
            'cost_price'     => $this->cost_price,
            'margin'         => $this->margin,
            'stock_quantity' => $this->stock_quantity,
            'stock_alert'    => $this->stock_alert,
            'is_low_stock'   => $this->isLowStock(),
            'image'          => $this->image
                                    ? self::resolveStorageUrl($this->image)
                                    : null,
            'is_active'      => $this->is_active,
            'created_at'     => $this->created_at->toIso8601String(),
            'updated_at'     => $this->updated_at->toIso8601String(),
        ];
    }
}
