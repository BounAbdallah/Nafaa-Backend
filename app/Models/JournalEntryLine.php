<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalEntryLine extends Model
{
    protected $fillable = [
        'journal_entry_id', 'account_id', 'debit', 'credit', 'label',
    ];

    protected $casts = [
        'debit'  => 'float',
        'credit' => 'float',
    ];

    public function entry(): BelongsTo   { return $this->belongsTo(JournalEntry::class, 'journal_entry_id'); }
    public function account(): BelongsTo { return $this->belongsTo(Account::class); }
}
