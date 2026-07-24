<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProcedureVersion extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'procedure_id',
        'version_number',
        'pdf_url',
        'infographic_url',
        'video_url',
        'published_at',
        'archived_at'
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }

    public function embeddings(): HasMany
    {
        return $this->hasMany(Embedding::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}