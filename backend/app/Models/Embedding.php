<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Embedding extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'procedure_version_id', 'chunk_text', 'chunk_index', 'embedding'];

    // We don't need timestamps for embeddings if we didn't add them in the migration
    public $timestamps = false; 

    public function procedureVersion(): BelongsTo
    {
        return $this->belongsTo(ProcedureVersion::class, 'procedure_version_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}

