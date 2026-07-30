<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KaizenReport extends Model
{
    protected $fillable = [
        'filiale_id',
        'procedure_id',
        'user_id',
        'criticality',
        'description',
        'status',
        'process_owner_id',
    ];

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function processOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'process_owner_id');
    }

    public function filiale(): BelongsTo
    {
        return $this->belongsTo(Filiale::class);
    }
}