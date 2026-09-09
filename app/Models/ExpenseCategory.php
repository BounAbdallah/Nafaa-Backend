<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ExpenseCategory extends Model
{
    protected $fillable = ['tenant_id', 'slug', 'label'];

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }

    public static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model) {
            if (empty($model->slug)) {
                $model->slug = Str::slug($model->label);
            }
        });
    }
}
