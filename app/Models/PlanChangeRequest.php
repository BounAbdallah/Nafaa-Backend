<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanChangeRequest extends Model
{
    protected $fillable = [
        'tenant_id',
        'requested_by',
        'current_pack_id',
        'requested_pack_id',
        'note',
        'status',
        'admin_note',
        'decided_at',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function currentPack(): BelongsTo
    {
        return $this->belongsTo(Pack::class, 'current_pack_id');
    }

    public function requestedPack(): BelongsTo
    {
        return $this->belongsTo(Pack::class, 'requested_pack_id');
    }
}
