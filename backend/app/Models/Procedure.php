<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Procedure extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'reference',
        'name',
        'module',
        'status',
        'created_by',
        'current_version_id',
        'tenant_id',
    ];

    /**
     * The user who created the procedure.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The owning tenant/store.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * The currently active version of the procedure.
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(ProcedureVersion::class, 'current_version_id');
    }
}
