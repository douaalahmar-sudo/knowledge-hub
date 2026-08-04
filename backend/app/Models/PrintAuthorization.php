<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One authorized print (§11.1) — see the migration for why this is per-print
 * rather than a standing permission.
 */
class PrintAuthorization extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    /** No `updated_at`; `used_at` is the only mutation this row ever takes. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'filiale_id',
        'article_id',
        'user_id',
        'granted_by',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /**
     * Usable exactly once, and only inside its window.
     *
     * Expiry is checked against the server clock on consume, never against the
     * client's: the whole grant is worthless if the holder can decide when it
     * ends.
     */
    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    /** Who may print under this grant. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Who authorized it — the same person, in today's self-service flow. */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
