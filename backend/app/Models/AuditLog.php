<?php

namespace App\Models;

use App\Enums\AuditAction;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One entry in the journal d'audit (§10.4).
 *
 * Written through App\Services\AuditLogger, never constructed directly by a
 * controller — that is what keeps the actor, the address and the filiale from
 * being re-derived (and derived differently) at each call site.
 *
 * Immutability is enforced by the database, not here: the RLS policy set on
 * `audit_logs` creates no UPDATE or DELETE policy, so those commands match no
 * rows whoever issues them. This model simply has no reason to offer them.
 */
class AuditLog extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * `updated_at` does not exist on this table — an audit entry is a statement
     * about a moment and is never revised. Eloquent would otherwise try to
     * write the column on every insert.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'filiale_id',
        'user_id',
        'action',
        'auditable_type',
        'auditable_id',
        'ip_address',
        'metadata',
    ];

    protected $casts = [
        // Not cast to AuditAction: the column deliberately accepts actions this
        // enum does not list yet (see the migration), and a cast would throw on
        // reading a historical row whose action has since been renamed or
        // retired. Callers get the string; AuditAction is for writing.
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    /** The actor. Null for system-originated entries — see the migration. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function filiale(): BelongsTo
    {
        return $this->belongsTo(Filiale::class);
    }

    /**
     * The article, alert or other record the action was performed on.
     *
     * May resolve to null even when set: the target can be hard-deleted while
     * the entry survives, which is intended. Callers must not assume a loaded
     * relation is non-null.
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The enum case for this row's action, or null if the string predates the
     * enum or has been retired from it. Lets a caller do
     * `$log->actionCase()?->label()` without risking a ValueError.
     */
    public function actionCase(): ?AuditAction
    {
        return AuditAction::tryFrom($this->action);
    }
}
