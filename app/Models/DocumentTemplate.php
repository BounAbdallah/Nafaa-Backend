<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentTemplate extends Model
{
    protected $fillable = ['tenant_id','name','type','content','description','is_default'];
    protected $casts    = ['is_default' => 'boolean'];

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
}
