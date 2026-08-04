<?php

namespace App\Models;

use App\Enums\SecurityAlertType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An automated security alert (§10.4) — raised by
 * App\Services\SecurityAnomalyDetector, never by a user action.
 *
 * Append-only in the database, like AuditLog: the RLS policy set creates no
 * UPDATE or DELETE policy, so an alert cannot be quietly rewritten or removed
 * by the account it names.
 */
class SecurityAlert extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    /** No `updated_at` column — see the migration. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'filiale_id',
        'user_id',
        'alert_type',
        'details',
    ];

    protected $casts = [
        // Same reasoning as AuditLog::$casts: the column accepts types this
        // enum may not list forever, and a cast would throw when reading a
        // historical row whose detector has since been retired.
        'details' => 'array',
        'created_at' => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    /** The account the alert is about. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function filiale(): BelongsTo
    {
        return $this->belongsTo(Filiale::class);
    }

    /** The enum case, or null for a type that predates or postdates the enum. */
    public function typeCase(): ?SecurityAlertType
    {
        return SecurityAlertType::tryFrom($this->alert_type);
    }
}
