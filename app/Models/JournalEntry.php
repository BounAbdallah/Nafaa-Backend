<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class JournalEntry extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'user_id', 'reference', 'description',
        'entry_date', 'period', 'source', 'source_id', 'is_locked',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'is_locked'  => 'boolean',
    ];

    public function tenant(): BelongsTo  { return $this->belongsTo(Tenant::class); }
    public function user(): BelongsTo    { return $this->belongsTo(User::class); }
    public function lines(): HasMany     { return $this->hasMany(JournalEntryLine::class); }

    /** Vérifie que la partie double est équilibrée (Σdébits == Σcrédits). */
    public function isBalanced(): bool
    {
        $debit  = $this->lines->sum('debit');
        $credit = $this->lines->sum('credit');
        return round($debit, 2) === round($credit, 2);
    }
}
