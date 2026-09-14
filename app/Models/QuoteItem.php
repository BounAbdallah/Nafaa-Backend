<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuoteItem extends Model
{
    public $timestamps = false;
    protected $fillable = ['quote_id','description','quantity','unit_price','total','sort_order'];

    public function quote(): BelongsTo { return $this->belongsTo(Quote::class); }
}
